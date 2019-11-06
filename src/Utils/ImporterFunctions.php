<?php
namespace Drupal\catalog_importer\Utils;

class ImporterFunctions{
  public static function catalog_importer_rebuild_term_cache($vid){
     \Drupal::cache('catalog_importer')
           ->delete($vid);
     self::catalog_importer_terms_cache($vid);
  }
  public static function catalog_importer_rebuild_all_term_caches(){
    $vocabs = \Drupal::config('catalog_importer.settings')->get('cached_vocabs');
    foreach($vocabs as $vocab){
      self::catalog_importer_rebuild_term_cache($vocab);
    }
  }
  public static function catalog_importer_rebuild_term_cache_submit($form, $form_state){
    $vid = explode("-",$form_state->getTriggeringElement()['#id'])[4];
    self::catalog_importer_rebuild_term_cache($vid);
  }
  public static function catalog_importer_terms_cache($vid){
    
    //$cid = $vid //. ": " . \Drupal::languageManager()
            //->getCurrentLanguage()
            //->getId();
     if ($cache = \Drupal::cache('catalog_importer')
       ->get($vid)) {
        \Drupal::logger('catalog_importer')->notice('Cache exists for @type',
        array(
            '@type' => $vid,
        ));
       return $cache->data;
     }
     \Drupal::logger('catalog_importer')->notice('Building new Cache for @type',
     array(
         '@type' => $vid,
     ));
    $terms =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vid); //, 0, NULL, TRUE
    $search = array();
    foreach ($terms as $term) {
      $search['terms']['priority'] = 10000;
      $name = strtolower($term->name);
      $search['terms'][$term->tid] = $name;
      $search[$name]['parent'] = (string) array_shift($term->parents);
    }
    foreach($search['terms'] as $tid => $name){
      if($tid == 'priority'){
        continue;
      }
      $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($tid);
      $contains = $term->get('field_contains')->getValue();
      $matches = $term->get('field_matches')->getValue();
      $starts = $term->get('field_starts')->getValue();
      $ends = $term->get('field_ends')->getValue();
      $priority = $term->get('field_priority')->getValue();
      $contains_all = $term->get('field_contains_all')->getValue();
      $search[$name]['matches'] = array();
      $search[$name]['contains'] = array();
      if(!empty($search[$name]['parent']) && $search[$name]['parent'] > 0){
        $search[$name]['parent'] = $search['terms'][$search[$name]['parent']];
      } else{
        unset($search[$name]['parent']);
      }
      
      $search[$name]['priority'] = !empty($priority) ? intval($priority[0]['value']) : 0; 
      if(empty($matches) && empty($contains) && empty($starts) && empty($ends) && empty($contains_all)){
        continue;
      }
      //Set Matches
      if(!empty($matches)){
        foreach($matches as $val){
          $search[$name]['matches'][]=strtolower($val['value']);
        }
      }
      if(!empty($contains_all)){
        foreach($contains_all as $val){
          if(strpos('--', $val['value'])){
            $val = explode("--", strtolower($val['value']));
            if(count($val) > 1){
              $search[$name]['contains_all'][]['contains'] = $val[0];
               $values = explode("|||", $val[1]);
               $search[$name]['contains_all'][]['not'] = array_map('trim', $values);
            } else {
              $search[$name]['contains_all'][]['contains'] = '';
              $values = explode("|||",$values[0]);
              $search[$name]['contains_all'][]['not'] = array_map('trim', $values);
            }
          }else{
            $val = explode("|||", strtolower($val['value']));
            $search[$name]['contains_all'][] = array_map('trim', $val);
          }
        }
      }
      //Set partial matches
      if(!empty($contains)){
        foreach($contains as $val){
          $search[$name]['contains'][]=strtolower($val['value']);
        }
      }
      //Set starts with
      if(!empty($starts)){
        foreach($starts as $val){
          $search[$name]['starts'][]=strtolower($val['value']);
        }
      }
      //Set ends with
      if(!empty($ends)){
        foreach($ends as $val){
          $search[$name]['ends'][]=strtolower($val['value']);
        }
      }

    }
    $settings = \Drupal::config('catalog_importer.settings')->get('vocab_settings');
    if(isset($settings[$vid]) && !empty($settings[$vid]['diff'])){
      $resourceVocabs = array_keys($settings[$vid]['diff']);

      foreach($resourceVocabs as $vocab){
        $search['terms'][$vocab] = array();
        $terms =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vocab); //, 0, NULL, TRUE

        foreach ($terms as $term) {
          $search['terms'][$vocab][$term->tid] = strtolower($term->name);
          $parents[$term->name] = (string) array_shift($term->parents);
        }
      }
    } 

    $tree = array();
    foreach($search as $name => $info){
      if($name == 'terms'){
        continue;
      }
      if(isset($info['parent'])){
        $tree[$info['parent']][] = $name; 
      }
    }
    if(!empty($tree)){
      $search['terms']['catalog_importer_term_tree'] = $tree;
    }

    uasort($search, function($a, $b) {
      return $a['priority'] <=> $b['priority'];
    });
    \Drupal::cache('catalog_importer')
      ->set($vid, $search, \Drupal\Core\Cache\CacheBackendInterface::CACHE_PERMANENT);
    return $search;
  }
}