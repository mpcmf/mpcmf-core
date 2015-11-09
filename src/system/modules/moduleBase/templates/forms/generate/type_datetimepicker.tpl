<div style="position: relative">
    <input
            id="alt-item-{$fieldName}"
            name="item[{$fieldName}]"
            {if isset($item)} value="{$item->getFieldValue($fieldName)}"{/if}
            {if isset($field.options.required) && $field.options.required} required{/if}
            {if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key'] || isset($readonly) && $readonly} disabled{/if}
            hidden
            >
    <input
            id="item-{$fieldName}"
            name="useless[{$fieldName}]"
            class="form-control date timepicker"
            {if isset($item)} value="{$item->getFieldValue($fieldName)|date_format: "%Y%m%d %H:%M:%S"}"{/if}
            {if isset($field.options.required) && $field.options.required} required{/if}
            {if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key'] || isset($readonly) && $readonly} disabled{/if}
            >
</div>
<script>
    $(document).ready(function () {
        var dateTimePicker = $("#" + "item-{$fieldName}");
        dateTimePicker.datetimepicker({
            format: "YYYY-MM-DD HH:mm:ss"
        });
        dateTimePicker.on("dp.change", function ( ) {
            $("#" + "alt-item-{$fieldName}").val((Date.parse(dateTimePicker.val()) / 1000).toString());
        });
    });
</script>