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
    <span class="input-group-text"><i class="bi bi-clock"></i></span>
</div>
<script>
    $(document).ready(function () {
      new tempusDominus.TempusDominus(document.getElementById('item-{$fieldName}'),
        {
          display: {
            icons: {
              type: 'icons',
              time: 'bi bi-clock',
              date: 'bi bi-calendar-week',
              up: 'bi bi-arrow-up',
              down: 'bi bi-arrow-down',
              previous: 'bi bi-chevron-left',
              next: 'bi bi-chevron-right',
              today: 'bi bi-calendar-check',
              clear: 'bi bi-trash',
              close: 'bi bi-x',
            },
            buttons: {
              today: true,
              clear: true,
              close: true
            },
            components: {
              calendar: false,
              date: false,
              month: false,
              year: false,
              clock: true,
              hours: true,
              minutes: true,
              seconds: true,
            },
          },
          localization: {
            decrementHour: '',
            decrementMinute: '',
            decrementSecond: '',
            incrementHour: '',
            incrementMinute: '',
            incrementSecond: '',
            locale: 'default',
            clear: 'Очистить',
            close: 'Закрыть',
            format: 'HH:mm:ss',
          },
        })



      $('#item-{$fieldName}').on('change.td', function () {
        let time = $("#" + "item-{$fieldName}").val();
        let exploded = time.split(':');
        let seconds = (parseInt(exploded[0]) * 60 + parseInt(exploded[1])) * 60;
        $("#" + "alt-item-{$fieldName}").val(seconds.toString());
      })
    });
</script>