{if $field.formType=='select' || $field.formType=='searchebleselect'}
    {if !empty($field.relations)}
        {assign var="relationMapper" value=$_entity->getMapper()->getRelationMapper($fieldName)}
        {if $relationMapper->getEntityActions()->getAction('crud.list') !== false}
            <a href="{$_application->getUrl("/{$relationMapper->getModuleName()}/{$relationMapper->getEntityName()}/crud.list", [])}">{$field.name}</a>
        {/if}
    {else}
        {$field.name}
    {/if}
{else}
    {$field.name}
{/if}
