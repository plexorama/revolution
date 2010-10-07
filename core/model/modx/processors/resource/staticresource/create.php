<?php
/**
 * @package modx
 * @subpackage processors.resource.staticresource
 */

/* set fields */
$resource->fromArray($scriptProperties);
if (!$resource->get('class_key')) {
    $resource->set('class_key', $resourceClass);
}

/* increase menu index if this is a new resource */
$auto_menuindex = $modx->getOption('auto_menuindex',null,true);
if (!empty($auto_menuindex) && empty($scriptProperties['menuindex'])) {
    $scriptProperties['menuindex'] = $modx->getCount('modResource',array(
        'parent' => $resource->get('parent'),
        'context_key' => $scriptProperties['context_key'],
    ));
}
$resource->set('menuindex',!empty($scriptProperties['menuindex']) ? $scriptProperties['menuindex'] : 0);

/* invoke OnBeforeDocFormSave event, and allow non-empty responses to prevent save */
$OnBeforeDocFormSave = $modx->invokeEvent('OnBeforeDocFormSave',array(
    'mode' => modSystemEvent::MODE_NEW,
    'id' => 0,
    'resource' => &$resource,
));
if (is_array($OnBeforeDocFormSave)) {
    $canSave = false;
    foreach ($OnBeforeDocFormSave as $msg) {
        if (!empty($msg)) {
            $canSave .= $msg."\n";
        }
    }
} else {
    $canSave = $OnBeforeDocFormSave;
}
if (!empty($canSave)) {
    return $modx->error->failure($canSave);
}

/* save data */
if ($resource->save() == false) {
    return $modx->error->failure($modx->lexicon('resource_err_save'));
}

/* add lock */
$resource->addLock();

/* update parent to be a container if user has save permission */
if ($parent && $parent->checkPolicy('save')) {
    $parent->set('isfolder', true);
    $parent->save();
}

/* save resource groups */
if (isset($scriptProperties['resource_groups'])) {
    $_GROUPS = $modx->fromJSON($scriptProperties['resource_groups']);
    foreach ($_GROUPS as $id => $group) {
        if ($group['access']) {
            $rgr = $modx->getObject('modResourceGroupResource',array(
                'document_group' => $group['id'],
                'document' => $resource->get('id'),
            ));
            if ($rgr == null) {
                $rgr = $modx->newObject('modResourceGroupResource');
            }
            $rgr->set('document_group',$group['id']);
            $rgr->set('document',$resource->get('id'));
            $rgr->save();
        } else {
            $rgr = $modx->getObject('modResourceGroupResource',array(
                'document_group' => $group['id'],
                'document' => $resource->get('id'),
            ));
            if ($rgr == null) continue;
            $rgr->remove();
        }
    }
}

/* quick check to make sure it's not site_start, if so, publish */
if ($resource->get('id') == $modx->getOption('site_start')) {
    $resource->set('published',true);
    $resource->save();
}

/* invoke OnDocFormSave event */
$modx->invokeEvent('OnDocFormSave',array(
    'mode' => modSystemEvent::MODE_NEW,
    'id' => $resource->get('id'),
    'resource' => & $resource
));

/* log manager action */
$modx->logManagerAction('save_resource', 'modStaticResource', $resource->get('id'));

if (!empty($scriptProperties['syncsite']) || !empty($scriptProperties['clearCache'])) {
    /* empty cache */
    $cacheManager= $modx->getCacheManager();
    $cacheManager->clearCache(array (
            "{$resource->context_key}/",
        ),
        array(
            'objects' => array('modResource', 'modContext', 'modTemplateVarResource'),
            'publishing' => true
        )
    );
}

$resource->removeLock();

return $modx->error->success('', array('id' => $resource->get('id')));