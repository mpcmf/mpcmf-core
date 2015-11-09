{include file="crud/_page_title.tpl" title="Удаление объекта <strong>{$_entity->getEntityName()}</strong>"}

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
            <tr>
                <td>{$_entity->getMapper()->getFieldName($_entity->getMapper()->getKey())}</td>
                <td>
                    <input readonly type="text" class="form-control" value="{$_slim->router()->getCurrentRoute()->getParam($_entity->getMapper()->getKey())}"
                            >
                </td>
            </tr>
            <tr>
                <td colspan="2">{include file="forms/generate/type_submit.tpl"}</td>
            </tr>
        </tbody>
    </table>
</form>