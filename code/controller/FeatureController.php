<?php
/**
 * @package geoviewer
 * @subpackage controller
 * @author Rainer Spittel (rainer at silverstripe dot com)
 *
 */

/**
 * 
 *
 * @package geoviewer
 * @subpackage controller
 * @author Rainer Spittel (rainer at silverstripe dot com)
 */
class Feature_Controller extends Controller {

	public static $url_handlers = array(
		'dogetfeatureinfo/$ID/$OtherID' => 'dogetfeatureinfo',
		'dogetfeature/$ID/$OtherID' => 'dogetfeature'
	);
	

	static $template_name = array(
		'GetFeature' => 'GetFeature',
		'GetFeatureInfo' => 'GetFeatureInfo'
	);

	static $allowed_actions = array(
		'dogetfeatureinfo',
		'dogetfeature'
	);
	
	/**
	 *
	 */
	static function set_template_name($value) {
		self::$template_name = $value;
	}
	
	/**
	 *
	 */
	static function get_template_name($key) {
		return isset(self::$template_name[$key]) ? self::$template_name[$key] : null;
	}
	
	/**
	 * This method determines which Action names need to be executed
	 * to retrieve the requested information. It takes the type of 
	 * connector into consideration.
	 *
	 * NOTE: at this stage, this method does not take the URL of a
	 * connector into consideration. This means following case is not
	 * covered: you want to send a get-feature-info request to two dedicated
	 * geoserver instances.
	 *
	 * @param $layers DataObjectSet of selected layers to run the query on.
	 * @param $action String, name of the action which will be used to determine the command name.
	 *
	 * @return array array structure for each connector type.
	 *
	 * array(
	 *   'GetFeatureInfoCommand' => array(
	 *     'URL' => 'http://localhost:8080/geoserver/service/wms',
	 *     'Action' => 'GetFeatureInfoCommand',
	 *     'Layers' => array(
	 *        Layer-DataObject1,
	 *        Layer-DataObject2,
	 *     )
	 *   )
	 * )
	 */
	private function getActions($layers,$action) {
		$actions = array();
		foreach($layers as $layer) {

			$commandName = $layer->getActionName($action);
			$storage = $layer->Storage();
			
			$id = md5(sprintf('%s-%s',$commandName, $storage->URL));
			
			if (!isset($actions[$id])) {
				$set = new DataObjectSet();
				$set->push($layer);
				$actions[$id] = array(
					"URL" => $storage->URL,
					"Action" => $commandName,
					"Layers" => $set
				);
			} else {
				$actions[$id]['Layers']->push($layer);
			}
		}
		return $actions;
	}
	
	/**
	 * This method creates a Sapphire ORM data model for the returned OGC
	 * features and maps those to the data objects stored in the CMS.
	 *
	 * @param $features array 
	 *
	 * @return DataObjectSet
	 */
	public function mapOGC2ORM($features) {
		$response = new DataObjectSet();
		
		if (!$features) {
			return $response;
		}
		// get featuretypenames to retieve defined featuretypes from the CMS.
		$featureTypeNames = array();
		
		$func = function($value) {
			return $value['FeatureType'];
		};
		$featureTypeNames = array_unique(array_map($func, $features['ServerResult']['features']));
		
		$sql_segment = implode("','",$featureTypeNames);
		$featureTypes = DataObject::get("FeatureType",sprintf("\"Name\" in ('%s')",$sql_segment));


		// iterate through the feature types and create a dataobject set for the
		// template rendering, bringing together the feature-type dataobject with
		// the OGC response.
		if ($featureTypes) {
			foreach($features as $featureTypeName => $value) {
			
				// Is the returned feature type name stored in the CMS? If so, 
				// then add this feature type to the overall response.
				$DOFeatureType = $featureTypes->find('Name',$featureTypeName);
			
				if ($DOFeatureType) {
					// check if the layer already exists in the response dataobjectset.
					$layerID =  $DOFeatureType->LayerID;
					$layer = $response->find('ID',$layerID);
					if (!$layer) {
					
						// layer not in the response list, create new layer entry
						$layer = new ArrayData(array(
							'ID' => $layerID,
							'Layer' => $DOFeatureType->Layer(),
							'FeatureTypes' => new DataObjectSet(),
							'scope' => 'Layers'
						));
						$response->push($layer);				
					}

					$layer->FeatureTypes->push( new ArrayData(array(
						'FeatureType' => $DOFeatureType,
						'Features' => $value,
						'scope' => 'FeatureTypes'
					)));
				}
			}
		} else {
		}
		return $response;
	}
	
	/**
	 * Executes a list of command-actions.
	 * Retrieve list of requests we need to perform to process the query.
	 *
	 * @param array $commandList array generated by getActions method.
	 *
	 * Example:
	 * array(
	 *   array(
	 *     'GetFeatureInfoCommand' => array(
	 *       'URL' => 'http://localhost:8080/geoserver/service/wms',
	 *       'Action' => 'GetFeatureInfoCommand',
	 *       'Layers' => array(
	 *          Layer-DataObject1,
	 *          Layer-DataObject2,
	 *       )
	 *     )
	 *   )
	 * );
	 *
	 * @param array $param list of OGC WMS request parameters
	 *
	 */
	private function executeCommands($commandList, $param) {
		$response_features = array();
		
		foreach($commandList as $item) {
			
			// initiate command parameters for the WMS-GetFeatureInfo request
			$commandName = $item['Action'];
			$commandLayers = $item['Layers'];
			$commandUrl = $item['URL'];
			
			$namespaces = $commandLayers->map("Namespace","Namespace");

			// translate internal layerID into Layer names.
			$param['LAYERS'] = implode(',',$commandLayers->map("ID","LayerName"));
			
			try {
				// get command and execute command
				$cmd = $this->getCommand($commandName, array(
						'URL' => $commandUrl,
						'Namespace' => $namespaces,
						'HTTP_parameters' => $param
					)
				);
				$result = $cmd->execute();
			}
			catch(Exception $exception) {
			}

			$response_features[] = array(
				'Namespace' => $namespaces,
				'ServerResult' => $result,
				'Layers' => $commandLayers,
			);

		}
		return $response_features;
	}
	
	/**
	 * This method is triggered by a mouse click on a WMS layer of the map.
	 * Following scenario need to be considered:
	 *
	 * - request for one wms layer, one storage, one feature type 
	 * - request for one wms layer, one storage, m feature type 
	 * - request for n wms layers, one storage, one feature type each
	 * - request for n wms layers, one storage, m feature type each
	 * - request for one wms layer, x storages, one feature type 
	 * - request for one wms layer, x storages, m feature type 
	 * - request for n wms layers, x storages, one feature type each
	 * - request for n wms layers, x storages, m feature type each
	 *
	 * This action sends a GetFeatureInfo request to the WMS.
	 * 
	 * @return String HTML
	 */
	public function dogetfeatureinfo($request) {
		$action = "GetFeatureInfo";

		// FeatureTypeName
		//   LayerID list
		//   FeatureType Object
		//   Features
		
		$param = $request->getVars();
		
		$layerID = $param['LAYERS'];
		$layerDos = DataObject::get("Layer",sprintf("\"Layer\".\"ID\" in (%s) AND \"Queryable\" = 1 AND \"Enabled\" = 1", Convert::raw2sql($layerID)));

		$commandList = $this->getActions($layerDos, "GetFeatureInfo");

		$response_features = $this->executeCommands($commandList, $param);
		
		/*
			$response_features :
		
			array (
			 	array(
					'Namespace' => $namespaces,
					'ServerResult' => array(
						'features' => array(
							'Namespace' => namespace,
							'FeatureType' => featuretype,
							'properties' => array (
								[ key => value ]
							)
						),
					    'featureTypesNames' => array(
							'tiger:tiger_roads', 
							'tiger:poly_landmarks'
						)
					),
					'Layers' => DataObjectSet[ Layer objects ],
				)
			);
		*/		
		
		$returnTemplate = array();
		// sort response by feature type names 
		foreach($response_features as $features) {

			$result = array();

			$featureTypeNameList = $features['ServerResult']['featureTypesNames'];

			// re-arrange rsult: sort list by feature types
			foreach( $featureTypeNameList as $featureTypeName) {

				// return all feature types of the current selected featuretype name
				$func = function($value) use ($featureTypeName) {
					return $value['Namespace'].":".$value['FeatureType'] == $featureTypeName;
				};

				// get FeatureType DataObject
				$layerIDs = implode(',',array_keys($layerDos->map("ID","ID")));

				$queryItems = explode(':',$featureTypeName);

				$Namespace = $queryItems[0];				
				$FeatureType = $queryItems[1];				
				
				// get the closes featuretype dataobject.
				// one known issue: the list of arrays may refer to the same feature type more than once.
				// this query will not detect this. 
				// in general, the requests would need to be split in two requests to implement this.
				$featureTypeObj = DataObject::get_one("FeatureType",sprintf("\"Namespace\" = '%s' AND \"Name\" = '%s' AND \"LayerID\" in (%s)", Convert::raw2sql($Namespace),Convert::raw2sql($FeatureType),$layerIDs));			
				
				$featureArray =  array_filter( $features['ServerResult']['features'], $func );
				
				// create result array
				$result[$featureTypeName] = array(
					"FeatureTypeName" => $featureTypeName,
				);


				$viewableData = new ViewableData();
				$dataObjectSet = new DataObjectSet();

				// convert feature properties into a template data structure
				foreach($featureArray as $feature) {
					$dataObjectSet->push(
						new ArrayData($feature['properties'])
					);
				}

				$viewableData->customise( array(
					"Layer" => $featureTypeObj->Layer(),
					"FeatureTypes" => $featureTypeObj,
					"Features" => $dataObjectSet
				));

				$template = $featureTypeObj->FeatureTypeTemplate;

				// if a template in the CMS is defined, use the template insteat of the default template.
				if ($template) {
					$viewer = SSViewer::fromString($template);
					$returnTemplate[] = $viewableData->renderWith( $viewer );
				} else {
					$returnTemplate[] =  $viewableData->renderWith( self::get_template_name($action) );
				}
			}
		}
		return implode('',$returnTemplate);
	}	
	
	/**
	 * Processes params and finds if request is for a single or multiple stations.
	 * if single station calls renderSingleStation method with station and layers values
	 * if multiple stations create list with stations to render HTML
	 * if not stationID displays message
	 *
	 * Following scenario need to be considered:
	 *
	 * - request for one wfs layer, one storage, one feature type 
	 *
	 * @param Request $request
	 *
	 * @throws Feature_Controller_Exception
	 *
	 * @return string HTML segment
	 */
	public function dogetfeature( $request ) {
		$action = "GetFeature";
		
		if( $request->param("ID") == "" || $request->param("OtherID") == "" ) {
			throw new Feature_Controller_Exception('Mandatory request parameters not provided.');
		}
		
		$mapID = (Integer)$request->param("ID");
		$featureIDs = Convert::raw2sql($request->param("OtherID"));
		
		$output = "Sorry we cannot retrieve feature information, please try again.";

		// Determin the layer (The string has a structure like: featureType.featureID)
		// We use the featureType to determin the layer object, used to send
		// the get feature request.
		$featureStructure = explode(".", $featureIDs); 
		
		if(count($featureStructure) <= 1) {
			throw new Feature_Controller_Exception('Invalid feature-ID structure.');
		}
		$featureType = Convert::raw2sql($featureStructure[0]);

		$layer = DataObject::get_one('Layer',sprintf("FeatureType = '%s' AND MapID = '%s'",$featureType,$mapID));
		
		if (!$layer) {
			throw new Feature_Controller_Exception(sprintf("Unknown feature type: '%s'",$featureType));
		}

		$data = array(
			'Layer' => $layer,
			'featureID' => $featureIDs
		);
		
		// get commend, i.e., GeoserverWFS_GetFeature
		$commandName = $layer->getActionName($action);
		$cmd = $this->getCommand($commandName, $data);

		$result = $cmd->execute();

		$viewableData = new ViewableData();
		
		$featureTypeObj = DataObject::get_one("FeatureType",sprintf("\"Name\" = '%s' AND \"LayerID\" = '%s'", Convert::raw2sql($featureType),$layer->ID));			
		
		
		if ($featureTypeObj == null) {
			throw new Feature_Controller_Exception(sprintf("Feature Type '%s' not defined. Can not render result. Please contact system administrator.",$featureType));
		}
		
		$features = array();
		$dataObjectSet = new DataObjectSet();

		if (isset($result['features'])) {
			$features = $result['features'];
		}

		// convert feature properties into a template data structure
		foreach($features as $feature) {
			$dataObjectSet->push(
				new ArrayData($feature['properties'])
			);
		}

		$viewableData->customise( array(
			"Layer" => $layer,
			"FeatureTypes" => $layer->FeatureTypes(),
			"Features" => $dataObjectSet,
			"FeatureIDs" => $featureIDs
		));
		
		$template = $featureTypeObj->FeatureTypeTemplate;
		
		// if a template in the CMS is defined, use the template insteat of the default template.
		if ($template) {
			$viewer = SSViewer::fromString($template);
			return $viewableData->renderWith( $viewer );
		}
		
		return $viewableData->renderWith( self::get_template_name($action) );
	}
}

/**
 *
 */
class Feature_Controller_Exception extends Exception {
}