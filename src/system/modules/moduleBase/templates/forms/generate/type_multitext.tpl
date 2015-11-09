<script>
    if(multitextInitialized == undefined) {
        var multitextInitialized = true;
        $(document).ready(function () {
            var body = $('body');
            body.on('click', '.filterParams .add', function () {
                var index = parseInt($('.filterParams .form-control').last().attr('index')) + 1;
                $(this).parent().parent()
                        .append('<div class="col-md-12" style="padding-left: 0; padding-right: 0"><div class="col-md-10" style="padding-left: 0; padding-right: 0"><input type="text" class="form-control" id="item-{$fieldName}-' + index + '" index="' + index + '" name="item[{$fieldName}][]" value="" {if isset($readonly) && $readonly} readonly{/if}{if isset($field.options.required) && $field.options.required} required{/if}{if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key']} disabled{/if}></div><button type="button" class="add col-md-1 btn btn-default"><span class="fa fa-plus"></span></button><button type="button" class="remove col-md-1 btn btn-default"><span class="fa fa-minus"></span></button></div>')
                        .fadeIn('slow')
                ;
            });
            body.on('click', '.filterParams .remove', function () {
                var filterParamsInputs = $(this).parent().parent().children();
                var count = filterParamsInputs.length;
                if (count > 1) {
                    $(this).parent().remove();
                } else {
                    filterParamsInputs.val('');
                }
            });
        });
    }
</script>
<div class="filterParams">
{if isset($item) && !empty($item->getFieldValue($fieldName))}
    {foreach from=$item->getFieldValue($fieldName) key="index" item="filterValue"}
        <div class="col-lg-12" style="padding-left: 0; padding-right: 0">
            <div class="col-md-10" style="padding-left: 0; padding-right: 0">
                <input
                        type="text"
                        class="form-control"
                        id="item-{$fieldName}-{$index}"
                        index="{$index}"
                        name="item[{$fieldName}][]"
                        value="{$filterValue|htmlspecialchars}"
                        {if isset($readonly) && $readonly} readonly{/if}
                        {if isset($field.options.required) && $field.options.required} required{/if}
                        {if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key']} disabled{/if}
                        >
            </div>
            <button type="button" class="add col-md-1 btn btn-default {if isset($readonly) && $readonly} disabled{/if}"><span class="fa fa-plus"></span></button>
            <button type="button" class="remove col-md-1 btn btn-default {if isset($readonly) && $readonly} disabled{/if}"><span class="fa fa-minus"></span></button>
        </div>
    {/foreach}
{else}
<div class="col-md-12" style="padding-left: 0; padding-right: 0">
    <div class="col-md-10" style="padding-left: 0; padding-right: 0">
        <input
                type="text"
                class="form-control"
                id="item-{$fieldName}-0"
                index="0"
                name="item[{$fieldName}][]"
                value=""
                {if isset($readonly) && $readonly} readonly{/if}
                {if isset($field.options.required) && $field.options.required} required{/if}
                {if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key']} disabled{/if}
                >
    </div>
    <button type="button" class="add col-md-1 btn btn-default {if isset($readonly) && $readonly} disabled{/if}"><span class="fa fa-plus"></span></button>
    <button type="button" class="remove col-md-1 btn btn-default {if isset($readonly) && $readonly} disabled{/if}"><span class="fa fa-minus"></span></button>
</div>
{/if}
</div>