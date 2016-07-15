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
        function insertOsmTagFilter(&$sSQL, $tgt)
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
						
                        $sSQL .= "( ".$tgt.".class = '" . $item[0] . "'";
						if (count($items) > 1){
							// Has type
							$item[1] = $this->oDB->escapeSimple($items[1]); // Prevent SQL injection
							$sSQL .= " and ".$tgt.".type = '" . $items[1] ."'";
						}
						
                        if (count($items)> 2)
                        {
                            // Has an admin level
							$item[2] = $this->oDB->escapeSimple($items[2]); // Prevent SQL injection
                            $sSQL .= " and ".$tgt.".admin_level = " . $items[2];
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

				$sSQL  = 'SELECT comb.place_id FROM (';
				$this->getNestedQuery($sSQL, $sPointSQL, $fSearchDiam);
				$sSQL .= ') AS comb';
				// Find the closest object. If there're multiple closest (all within the same bounding area), find the smallest object.
				$sSQL .= ' ORDER BY ST_Distance(geography('.$sPointSQL.'), geography(comb.geometry)) ASC, ST_Area(comb.geometry) ASC ';
				$sSQL .= ' limit 1';
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
		
		function getNestedQuery(&$sSQL,$sPointSQL, $fSearchDiam){
			$sSQL .= ' SELECT pt1.place_id, pt1.geometry FROM placex AS pt1';
			$sSQL .= ' WHERE pt1.linked_place_id is null and ST_DWithin('.$sPointSQL.', pt1.geometry, '.$fSearchDiam.')';
			$this->insertOsmTagFilter($sSQL, 'pt1');
			$sSQL .= ' UNION';
			$sSQL .= ' SELECT pt2.place_id, ref.geometry FROM placex AS pt2';
			$sSQL .= ' LEFT JOIN placex AS ref ON pt2.linked_place_id=ref.place_id';
			$sSQL .= ' WHERE pt2.linked_place_id is not null and ST_DWithin('.$sPointSQL.', ref.geometry, '.$fSearchDiam.')';
			$this->insertOsmTagFilter($sSQL, 'pt2');
		}
		
	}
?>
