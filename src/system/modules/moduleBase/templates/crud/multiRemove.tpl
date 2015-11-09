{include file="crud/_page_title.tpl" title="Удаление объектов <strong>{$data.items|count}</strong>"}

{include file="index/catchResponse.tpl"}

<form method="post">
    <input type="hidden" name="confirm" value="yes">
    {foreach from=$data.items item="receivedItem"}
        <input type="hidden" name="items[]" value="{$receivedItem}">
    {/foreach}
    <table class="table table-striped table-bordered table-hover">
        <thead>
        <tr>
            <th class="col-lg-4">ID объекта</th>
        </tr>
        </thead>
        <tbody>
        {foreach from=$data.items item="item"}
            <tr>
                <td>{$item|htmlspecialchars}</td>
            </tr>
        {/foreach}
            <tr>
                <td>{include file="forms/generate/type_submit.tpl"}</td>
            </tr>
        </tbody>
    </table>
</form>