<?php
/**
 * mm_ddSelectDocuments
 * @version 1.3 (2016-06-06)
 * 
 * @desc A widget for ManagerManager that makes selection of documents ids easier.
 * 
 * @uses PHP >= 5.4.
 * @uses MODXEvo.plugin.ManagerManager >= 0.7.
 * 
 * @param $params {array_associative|stdClass} — The object of params. @required
 * @param $params['fields'] {string_commaSeparated} — TVs names that the widget is applied to. @required
 * @param $params['parentIds'] {string_commaSeparated} — Parent documents IDs. Default: '0'.
 * @param $params['depth'] {integer} — Depth of search. Default: 1.
 * @param $params['filter'] {separated string} — Filter clauses, separated by '&' between pairs and by '=' between keys and values. For example, 'template=15&published=1' means to choose the published documents with template id=15. Default: ''.
 * @param $params['listItemLabelMask'] {string} — Template to be used while rendering elements of the document selection list. It is set as a string containing placeholders for document fields and TVs. Also, there is the additional placeholder “[+title+]” that is substituted with either “menutitle” (if defined) or “pagetitle”. Default: '[+title+] ([+id+])'.
 * @param $params['maxSelectedItems'] {integer} — The largest number of elements that can be selected by user (“0” means selection without a limit). Default: 0.
 * @param $params['allowDuplicates'] {boolean} — Allows to select duplicates values. Default: false.
 * @param $params['roles'] {string_commaSeparated} — Roles that the widget is applied to (when this parameter is empty then widget is applied to the all roles). Default: ''.
 * @param $params['templates'] {string_commaSeparated} — Templates IDs for which the widget is applying (empty value means the widget is applying to all templates). Default: ''.
 * 
 * @event OnDocFormPrerender
 * @event OnDocFormRender
 * 
 * @link http://code.divandesign.biz/modx/mm_ddselectdocuments/1.3
 * 
 * @copyright 2013–2016 DivanDesign {@link http://www.DivanDesign.biz }
 */

function mm_ddSelectDocuments($params){
	//For backward compatibility
	if (
		//The only one required “fields” parameter
		is_string($params) ||
		//Or not
		func_num_args() > 1
	){
		//Convert ordered list of params to named
		$params = ddTools::orderedParamsToNamed([
			'paramsList' => func_get_args(),
			'compliance' => [
				'fields',
				'roles',
				'templates',
				'parentIds',
				'depth',
				'filter',
				'maxSelectedItems',
				'listItemLabelMask',
				'allowDuplicates'
			]
		]);
	}
	
	//Defaults
	$params = (object) array_merge([
		'fields' => '',
		'parentIds' => '0',
		'depth' => 1,
		'filter' => '',
		'listItemLabelMask' => '[+title+] ([+id+])',
		'maxSelectedItems' => 0,
		'allowDuplicates' => false,
		'roles' => '',
		'templates' => ''
	], (array) $params);
	
	if (!useThisRule($params->roles, $params->templates)){return;}
	
	global $modx;
	$e = &$modx->Event;
	
	$output = '';
	
	if ($e->name == 'OnDocFormPrerender'){
		$pluginDir = $modx->config['site_url'].'assets/plugins/managermanager/';
		$widgetDir = $pluginDir.'widgets/ddselectdocuments/';
		
		$output .= includeJsCss($widgetDir.'ddselectdocuments.css', 'html');
		$output .= includeJsCss($widgetDir.'jquery-migrate-3.0.0.min.js', 'html', 'jquery-migrate', '3.0.0');
		$output .= includeJsCss($pluginDir.'js/jquery-ui-1.10.3.min.js', 'html', 'jquery-ui', '1.10.3');
		$output .= includeJsCss($widgetDir.'jQuery.ddMultipleInput-1.3.2.min.js', 'html', 'jquery.ddMultipleInput', '1.3.2');
		
		$e->output($output);
	}else if ($e->name == 'OnDocFormRender'){
		global $mm_current_page;
		
		$params->fields = tplUseTvs($mm_current_page['template'], $params->fields);
		if ($params->fields == false){return;}
		
		$params->filter = ddTools::explodeAssoc($params->filter, '&', '=');
		
		//Необходимые поля
		preg_match_all('~\[\+([^\+\]]*?)\+\]~', $params->listItemLabelMask, $matchField);
		
		$listDocs_fields = array_unique(array_merge(
			array_keys($params->filter),
			['pagetitle', 'id'],
			$matchField[1]
		));
		
		//Если среди полей есть ключевое слово «title»
		if (($listDocs_fields_titlePos = array_search('title', $listDocs_fields)) !== false){
			//Удалим его, добавим «menutitle»
			unset($listDocs_fields[$listDocs_fields_titlePos]);
			$listDocs_fields = array_unique(array_merge($listDocs_fields, ['menutitle']));
		}
		
		//Получаем все дочерние документы
		$listDocs = ddGetDocs(
			explode(',', $params->parentIds),
			$params->filter,
			$params->depth,
			$params->listItemLabelMask,
			$listDocs_fields
		);
		
		if (count($listDocs) == 0){return;}
		
		$listDocs = json_encode($listDocs, JSON_UNESCAPED_UNICODE);
		
		$output .= '//---------- mm_ddSelectDocuments :: Begin -----'.PHP_EOL;
		
		foreach ($params->fields as $field){
			$output .=
'
$j("#tv'.$field['id'].'").ddMultipleInput({source: '.$listDocs.', max: '.(int) $params->maxSelectedItems.', allowDoubling: '.(int) $params->allowDuplicates.'});
';
		}
		
		$output .= '//---------- mm_ddSelectDocuments :: End -----'.PHP_EOL;
		
		$e->output($output);
	}
}

//Рекурсивно получает все необходимые документы
function ddGetDocs(
	$parentIds = [0],
	$filter = [],
	$depth = 1,
	$labelMask = '[+pagetitle+] ([+id+])',
	$fields = ['pagetitle', 'id']
){
	//Получаем дочерние документы текущего уровня
	$docs = [];
	
	//Перебираем всех родителей
	foreach ($parentIds as $parent){
		//Получаем документы текущего родителя
		$tekDocs = ddTools::getDocumentChildrenTVarOutput($parent, $fields, 'all');
		
		//Если что-то получили
		if (is_array($tekDocs)){
			//Запомним
			$docs = array_merge($docs, $tekDocs);
		}
	}
	
	$result = [];
	
	//Если что-то есть
	if (count($docs) > 0){
		//Перебираем полученные документы
		foreach ($docs as $val){
			//Если фильтр пустой, либо не пустой и документ удовлетворяет всем условиям
			if (
				empty($filter) ||
				count(array_intersect_assoc($filter, $val)) == count($filter)
			){
				$val['title'] = empty($val['menutitle']) ? $val['pagetitle'] : $val['menutitle'];
				
				//Записываем результат
				$tmp = ddTools::parseText([
					'text' => $labelMask,
					'data' => $val,
					'mergeAll' => false
				]);
				
				if (strlen(trim($tmp)) == 0){
					$tmp = ddTools::parseText([
						'text' => '[+pagetitle+] ([+id+])',
						'data' => $val,
						'mergeAll' => false
					]);
				}
				
				$result[] = [
					'label' => $tmp,
					'value' => $val['id']
				];
			}
			
			//Если ещё надо двигаться глубже
			if ($depth > 1){
				//Сливаем результат с дочерними документами
				$result = array_merge($result, ddGetDocs(
					[$val['id']],
					$filter,
					$depth - 1,
					$labelMask,
					$fields
				));
			}
		}
	}
	
	return $result;
}
?>