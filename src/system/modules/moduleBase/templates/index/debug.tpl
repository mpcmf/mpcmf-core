<div class="row">
    <div class="col-lg-12">
        <h1 class="page-header">DEBUG</h1>
    </div>
    <!-- /.col-lg-12 -->
</div>
<!-- /.row -->
<div class="row">
<pre><code>
{$smarty.get|var_dump}
</code></pre>
    {*{assign var="model" value=$model->find()->getNext()}*}

    {*<form method="post">*}
    {*{foreach from=$model->getEntity()->getMapper()->getMap() key="fieldName" value="fieldItem"}*}
        {*<div class="row">*}
            {*<div class="col-lg-3">*}
                {*{$fieldName}*}
            {*</div>*}
            {*<div class="col-lg-9">*}
                {*{include file="forms/generate/type_{$fieldItem['formType']}.tpl" item=$fieldItem value=$fieldValue readonly=false}*}
            {*</div>*}
        {*</div>*}
    {*{/foreach}*}
    {*</form>*}

</div>
<!-- /.row -->