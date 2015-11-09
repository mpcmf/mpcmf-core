{include file="crud/_page_title.tpl" title="Обновление объектов <strong>{$data.items|count}</strong>"}

{include file="index/catchResponse.tpl"}

<form method="post">
    <input type="hidden" name="confirm" value="yes">
    {foreach from=$data.items item="receivedItem"}
        <input type="hidden" name="items[]" value="{$receivedItem}">
    {/foreach}
    <table class="table table-striped table-bordered table-hover">
        <thead>
        <tr>
            <th class="col-lg-4">Обновлять?</th>
            <th class="col-lg-4">Поле</th>
            <th class="col-lg-8">Значение</th>
        </tr>
        </thead>
        <tbody>
        {foreach from=$_entity->getMapper()->getMap() key="fieldName" item="field"}
            <tr>
                <label>
                    <td>
                        <input type="checkbox" name="update[{$fieldName}]" value="1">
                    </td>
                    <td>{$field.name}</td>
                </label>
                <td>{include file="forms/generate/type_{$field.formType}.tpl" fieldName=$fieldName field=$field}</td>
            </tr>
        {/foreach}
            <tr>
                <td colspan="3">
                    {include file="forms/generate/type_submit.tpl"}
                </td>
            </tr>
        </tbody>
    </table>
</form>