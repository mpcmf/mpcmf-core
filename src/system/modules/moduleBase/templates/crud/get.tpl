{assign var="title" value="Просмотр объекта <strong>{$_entity->getEntityName()}</strong>"}
{if isset($data.item)}
    {assign var="title" value="{$title}&nbsp;<small><a href=\"{$_slim->urlFor("{$_entity->getEntityUniqueName()}/crud.update", [$_entity->getMapper()->getKey() => $data.item->getIdValue()])}\">редактировать</a></small>"}
{/if}

{include file="crud/_page_title.tpl" title=$title}

<table class="table table-striped table-bordered table-hover">
    <thead>
    <tr>
        <th class="col-lg-4">Поле</th>
        <th class="col-lg-8">Значение</th>
    </tr>
    </thead>
    <tbody>
    {foreach from=$_entity->getMapper()->getMap() key="fieldName" item="field"}
        <tr>
            <td>{$field.name}</td>
            {if isset($data.item)}
                <td>{include file="forms/generate/type_{$field.formType}.tpl" fieldName=$fieldName field=$field item=$data.item readonly=true}</td>
            {else}
                <td>{include file="forms/generate/type_{$field.formType}.tpl" fieldName=$fieldName field=$field readonly=true}</td>
            {/if}
        </tr>
    {/foreach}
    </tbody>
</table>