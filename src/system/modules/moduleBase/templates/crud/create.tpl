{include file="crud/_page_title.tpl" title="Создание объекта <strong>{$_entity->getEntityName()}</strong>"}

{include file="index/catchResponse.tpl"}

<form method="post">
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
                    <td>{include file="forms/generate/type_{$field.formType}.tpl" fieldName=$fieldName field=$field item=$data.item}</td>
                {else}
                    <td>{include file="forms/generate/type_{$field.formType}.tpl" fieldName=$fieldName field=$field item=null}</td>
                {/if}
            </tr>
        {/foreach}
            <tr>
                <td colspan="2">
                    {include file="forms/generate/type_submit.tpl"}
                </td>
            </tr>
        </tbody>
    </table>
</form>