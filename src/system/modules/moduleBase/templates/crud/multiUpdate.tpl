{include file="crud/_page_title.tpl" title="Обновление объектов <strong>{$data.items|count}</strong>"}

{include file="index/catchResponse.tpl"}

<form method="post" name="multiupdate_form">
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
                        <input class="update_checkbox" type="checkbox" name="update[{$fieldName}]" value="1">
                    </td>
                    <td>{$field.name}</td>
                </label>
                <td>{include file="forms/generate/type_{$field.formType}.tpl" fieldName=$fieldName field=$field}</td>
            </tr>
        {/foreach}
        <tr>
            <td colspan="3">
                <div id="submit_form" class="btn btn-success">Подтвердить</div>
            </td>
        </tr>
        </tbody>
    </table>
</form>


<script>
    var UI = {
        form: document.forms['multiupdate_form'],
        boxes: $('form input.update_checkbox'),
        submit_form: $('#submit_form')
    };
    var Input = {
        checkRequired: function(){
            var boxes = UI.boxes;
            var item = null, input = null;
            for (var i = 0; i < boxes.length; i++) {
                item = boxes[i].parentNode.nextElementSibling.nextElementSibling;
                item = $(item).find('input').filter('[required]:visible');
                if(!boxes[i].checked && item.required) item.required = false;
            }
        }
    };
    window.onload = function () {
        UI.submit_form.on('click', function(e){
            Input.checkRequired();
            UI.form.submit();
        });
    };
</script>