<?php
/**
 * mm_ddSelectDocuments
 * @version 1.3 (2016-06-06)
 * 
 * @desc A widget for ManagerManager that makes selection of documents ids easier.
 * 
 * @uses PHP >= 5.4.
 * @uses MODXEvo.plugin.ManagerManager >= 0.6.
 * 
 * @param $fields {string_commaSeparated} — TVs names that the widget is applied to. @required
 * @param $roles {string_commaSeparated} — Roles that the widget is applied to (when this parameter is empty then widget is applied to the all roles). Default: ''.
 * @param $templates {string_commaSeparated} — Templates IDs for which the widget is applying (empty value means the widget is applying to all templates). Default: ''.
 * @param $parentIds {string_commaSeparated} — Parent documents IDs. Default: '0'.
 * @param $depth {integer} — Depth of search. Default: 1.
 * @param $filter {separated string} — Filter clauses, separated by '&' between pairs and by '=' between keys and values. For example, 'template=15&published=1' means to choose the published documents with template id=15. Default: ''.
 * @param $maxSelectedItems {integer} — The largest number of elements that can be selected by user (“0” means selection without a limit). Default: 0.
 * @param $listItemLabelMask {string} — Template to be used while rendering elements of the document selection list. It is set as a string containing placeholders for document fields and TVs. Also, there is the additional placeholder “[+title+]” that is substituted with either “menutitle” (if defined) or “pagetitle”. Default: '[+title+] ([+id+])'.
 * @param $allowDuplicates {boolean} — Allows to select duplicates values. Default: false.
 * 
 * @event OnDocFormPrerender
 * @event OnDocFormRender
 * 
 * @link http://code.divandesign.biz/modx/mm_ddselectdocuments/1.3
 * 
 * @copyright 2013–2016 DivanDesign {@link http://www.DivanDesign.biz }
 */

function mm_ddSelectDocuments(
	$fields = '',
	$roles = '',
	$templates = '',
	$parentIds = '0',
	$depth = 1,
	$filter = '',
	$maxSelectedItems = 0,
	$listItemLabelMask = '[+title+] ([+id+])',
	$allowDuplicates = false
){
	if (!useThisRule($roles, $templates)){return;}
	
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
		
		$fields = tplUseTvs($mm_current_page['template'], $fields);
		if ($fields == false){return;}
		
		$filter = ddTools::explodeAssoc($filter, '&', '=');
		
		//Необходимые поля
		preg_match_all('~\[\+([^\+\]]*?)\+\]~', $listItemLabelMask, $matchField);
		
		$listDocs_fields = array_unique(array_merge(
			array_keys($filter),
			['pagetitle', 'id'],
			$matchField[1]
		));
		
		//Если среди полей есть ключевое слово «title»
		if (($listDocs_fields_titlePos = array_search('title', $listDocs_fields)) !== false){
			//Удалим его, добавим «menutitle»
			unset($listDocs_fields[$listDocs_fields_titlePos]);
			$listDocs_fields = array_unique(array_merge($listDocs_fields, ['menutitle']));
		}
		
		//Рекурсивно получает все необходимые документы
		if (!function_exists('ddGetDocs')){function ddGetDocs(
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
				$tekDocs = ddTools::getDocumentChildrenTVarOutput($parent, $fields, false);
				
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
						$tmp = ddTools::parseText($labelMask, $val, '[+', '+]', false);
						
						if (strlen(trim($tmp)) == 0){
							$tmp = ddTools::parseText('[+pagetitle+] ([+id+])', $val, '[+', '+]', false);
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
		}}
		
		//Получаем все дочерние документы
		$listDocs = ddGetDocs(
			explode(',', $parentIds),
			$filter,
			$depth,
			$listItemLabelMask,
			$listDocs_fields
		);
		
		if (count($listDocs) == 0){return;}
		
		$listDocs = json_encode($listDocs, JSON_UNESCAPED_UNICODE);
		
		$output .= '//---------- mm_ddSelectDocuments :: Begin -----'.PHP_EOL;
		
		foreach ($fields as $field){
			$output .=
'
$j("#tv'.$field['id'].'").ddMultipleInput({source: '.$listDocs.', max: '.(int) $maxSelectedItems.', allowDoubling: '.(int) $allowDuplicates.'});
';
		}
		
		$output .= '//---------- mm_ddSelectDocuments :: End -----'.PHP_EOL;
		
		$e->output($output);
	}
}
?>