{include file="index/header.tpl"}

<div id="wrapper">
    {include file="index/navbar.tpl"}
    <div id="page-wrapper">
        {if isset($_template) && !empty($_template)}
            {include file=$_template}
        {else}
            {include file="index/default.tpl"}
        {/if}
    </div>
    <!-- /#page-wrapper -->
</div>
<!-- /#wrapper -->

<footer><small>{$_profiler::getStackAsString()}</small></footer>
{include file="index/footer.tpl"}