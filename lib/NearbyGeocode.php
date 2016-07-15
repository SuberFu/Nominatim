<?php
	class NearbyGeocode
	{
		protected $oDB;

		protected $fLat;
		protected $fLon;
        
		protected $osmTags = array();
		
		protected $aLangPrefOrder = array();

		protected $bIncludePolygonAsPoints = false;
		protected $bIncludePolygonAsText = false;
		protected $bIncludePolygonAsGeoJSON = false;
		protected $bIncludePolygonAsKML = false;
		protected $bIncludePolygonAsSVG = false;
		protected $fPolygonSimplificationThreshold = 0.0;


		function NearbyGeocode(&$oDB)
		{
			$this->oDB =& $oDB;
		}

		function setLanguagePreference($aLangPref)
		{
			$this->aLangPrefOrder = $aLangPref;
		}

		function setLatLon($fLat, $fLon)
		{
			$this->fLat = (float)$fLat;
			$this->fLon = (float)$fLon;
		}
		
        function setOsmTags($sTag)
        {
            $this->osmTags = array();
			if (isset($sTag))
			{
				$tags = explode(";", $sTag);
				foreach($tags as $tag)
				{
					$this->osmTags[] = $tag;
				}
			}
        }	
        function insertOsmTagFilter(&$sSQL)
        {
            if (!($this->osmTags == null))
            {
                $sSQL .= " and (";
                $queryAdded = false;
                foreach($this->osmTags as $tag)
                {
                    $items = explode(":",$tag);
                    if (count($items)>= 0)
                    {
                        // Has at least a class
                        if ($queryAdded){
                            // Not the first one, prepend 'or' operator
                            $sSQL .= " or ";
                        }else{
                            $queryAdded = true;
                        }
						$item[0] = $this->oDB->escapeSimple($items[0]); // Prevent SQL injection
						
                        $sSQL .= "( pt.class = '" . $item[0] . "'";
						if (count($items) > 1){
							// Has type
							$item[1] = $this->oDB->escapeSimple($items[1]); // Prevent SQL injection
							$sSQL .= " and pt.type = '" . $items[1] ."'";
						}
						
                        if (count($items)> 2)
                        {
                            // Has an admin level
							$item[2] = $this->oDB->escapeSimple($items[2]); // Prevent SQL injection
                            $sSQL .= " and pt.admin_level = " . $items[2];
                        }
                        $sSQL .= " )";
                    }
                }
                $sSQL .= ")";
                return $queryAdded;
            }
            return false;
        }		
		
		function setIncludePolygonAsPoints($b = true)
		{
			$this->bIncludePolygonAsPoints = $b;
		}

		function getIncludePolygonAsPoints()
		{
			return $this->bIncludePolygonAsPoints;
		}

		function setIncludePolygonAsText($b = true)
		{
			$this->bIncludePolygonAsText = $b;
		}

		function getIncludePolygonAsText()
		{
			return $this->bIncludePolygonAsText;
		}

		function setIncludePolygonAsGeoJSON($b = true)
		{
			$this->bIncludePolygonAsGeoJSON = $b;
		}

		function setIncludePolygonAsKML($b = true)
		{
			$this->bIncludePolygonAsKML = $b;
		}

		function setIncludePolygonAsSVG($b = true)
		{
			$this->bIncludePolygonAsSVG = $b;
		}

		function setPolygonSimplificationThreshold($f)
		{
			$this->fPolygonSimplificationThreshold = $f;
		}

		// returns { place_id =>, type => '(osm|tiger)' }
		// fails if no place was found
		function lookup()
		{
			$sPointSQL = 'ST_SetSRID(ST_Point('.$this->fLon.','.$this->fLat.'),4326)';

			// Find the nearest point
			$fSearchDiam = 0.0004;
			$iPlaceID = null;
			$aArea = false;
			$fMaxAreaDistance = 1;
			$bIsInUnitedStates = false;
			$bPlaceIsTiger = false;
			$bPlaceIsLine = false;
			while(!$iPlaceID && $fSearchDiam < $fMaxAreaDistance)
			{
				$fSearchDiam = $fSearchDiam * 2;

				$sSQL  = 'SELECT pt.place_id';
				$sSQL .= ' FROM placex AS pt';
				$sSQL .= ' LEFT JOIN placex AS ref ON COALESCE(pt.linked_place_id, pt.place_id)=ref.place_id';
				$sSQL .= ' WHERE ST_DWithin('.$sPointSQL.', ref.geometry, '.$fSearchDiam.')';
				if(!$this->insertOsmTagFilter($sSQL)){
					// Do nothing for now if no osmtag filter.
				}
				$sSQL .= ' and pt.indexed_status = 0 ';
				$sSQL .= ' and ref.indexed_status = 0 ';
				$sSQL .= ' ORDER BY ST_Distance(geography('.$sPointSQL.'), geography(ref.geometry)) ';
				$sSQL .= ' ASC limit 1';
				if (CONST_Debug) var_dump($sSQL);
				$aPlace = chksql($this->oDB->getRow($sSQL),"Could not determine closest place.");
				$iPlaceID = $aPlace['place_id'];
				$iParentPlaceID = $aPlace['parent_place_id'];
				$bIsInUnitedStates = ($aPlace['calculated_country_code'] == 'us');
			}
			
			return array('place_id' => $iPlaceID,
						'type' => $bPlaceIsTiger ? 'tiger' : ($bPlaceIsLine ? 'interpolation' : 'osm'),
						'fraction' => ($bPlaceIsTiger || $bPlaceIsLine) ? $fFraction : -1);
		}
		
	}
?>
