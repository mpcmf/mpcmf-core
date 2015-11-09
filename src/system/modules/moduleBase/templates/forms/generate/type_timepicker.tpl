<input
        id="alt-item-{$fieldName}"
        name="item[{$fieldName}]"
        value="{if isset($item)}{$item->getFieldValue($fieldName)}{else}0{/if}"
        {if isset($field.options.required) && $field.options.required} required{/if}
        {if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key'] || isset($readonly) && $readonly} disabled{/if}
        hidden
        >
<div class="input-group bootstrap-timepicker col-md-2">
    <input
            id="item-{$fieldName}"
            name="useless[{$fieldName}]"
            class="form-control"
            {if isset($item)}
            {assign var="seconds" value=$item->getFieldValue($fieldName)}
            {assign var="hours" value=($seconds / 3600)|floor}
            {assign var="minutes" value=($seconds / 60)|floor}
                value="{$hours}:{$minutes}"
            {else}
                value="00:00"
            {/if}
            {if isset($field.options.required) && $field.options.required} required{/if}
            {if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key'] || isset($readonly) && $readonly} disabled{/if}
            >
    <span class="input-group-addon"><i class="glyphicon glyphicon-time"></i></span>
</div>
<script>
    $(document).ready(function () {
        var timePicker = $("#" + "item-{$fieldName}");
        timePicker.timepicker({
            minuteStep: 1,
            secondStep: 1,
            showMeridian: false,
            defaultTime: false
        });
        timePicker.on('changeTime.timepicker', function () {
            var time = timePicker.val();
            var exploded = time.split(':');
            var seconds = (parseInt(exploded[0]) * 60 + parseInt(exploded[1])) * 60;
            $("#" + "alt-item-{$fieldName}").val(seconds.toString());
        });
    });
</script>