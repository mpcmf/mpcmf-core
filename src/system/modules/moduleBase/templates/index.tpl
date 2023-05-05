{if isset($_template) && !empty($_template)}
    {include file=$_template assign="templateContent"}
{else}
    {include file="index/default.tpl" assign="templateContent"}
{/if}
{include file="index/header.tpl"}

{block name=head}
    <link rel="stylesheet" href="/custom/sds/general/style.css">
{/block}

<div id="wrapper">
    {include file="index/navbar.tpl"}
    <div class="modal fade"
         id="infoModal"
         tabindex="-1"
         role="dialog"
         aria-labelledby="infoModalLabel"
         aria-hidden="true"
         style="display: none;">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header d-flex flex-row-reverse">
                    <button type="button" class="close btn btn-light" data-bs-dismiss="modal" aria-hidden="true">×
                    </button>
                    <h4 class="modal-title" id="infoModalLabel"></h4>
                </div>
                <div class="modal-body">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <div id="page-wrapper" class="mt-3 main-content">
        {$templateContent}
    </div>
</div>

<footer class="sds-footer"><small>{$_profiler::getStackAsString()}</small></footer>
{include file="index/footer.tpl"}