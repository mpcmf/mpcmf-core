{if $field.formType=='select' || $field.formType=='searchebleselect'}
    {assign var="relationMapper" value=$_entity->getMapper()->getRelationMapper($fieldName)}
    {if $relationMapper->getEntityActions()->getAction('crud.list') !== false}
        <a href="{$_application->getUrl("/{$relationMapper->getModuleName()}/{$relationMapper->getEntityName()}/crud.list", [])}">{$field.name}</a>
    {else}
        {$field.name}
    {/if}
{else}
    {$field.name}
{/if}
