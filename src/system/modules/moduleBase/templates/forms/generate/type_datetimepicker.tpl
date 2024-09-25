<div class="position-relative">
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
            {if isset($item)} value="{$item->getFieldValue($fieldName)|date_format: "%Y-%m-%d %H:%M:%S"}"{/if}
            {if isset($field.options.required) && $field.options.required} required{/if}
            {if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key'] || isset($readonly) && $readonly} disabled{/if}
    >
</div>

<script>
  $(document).ready(function () {

    const timePicker = new tempusDominus.TempusDominus(document.getElementById('item-{$fieldName}'),
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
            calendar: true,
            date: true,
            month: true,
            year: true,
            clock: true,
            hours: true,
            minutes: true,
            seconds: true,
          },
        },
        localization: {
          dayViewHeaderFormat: 'MMMM yyyy',
          decrementHour: '',
          decrementMinute: '',
          decrementSecond: '',
          incrementHour: '',
          incrementMinute: '',
          incrementSecond: '',
          locale: 'default',
          clear: 'Очистить',
          close: 'Закрыть',
          nextMonth: 'Следующий месяц',
          nextYear: 'Следующий год',
          previousMonth: 'Предыдущий месяц',
          previousYear: 'Предыдущий год',
          selectDate: 'Выбор даты',
          selectTime: 'Установить время',
          selectYear: 'Выбрать год',
          startOfTheWeek: 0,
          format: 'yyyy-MM-dd HH:mm:ss',
        },
      })


    $('#item-{$fieldName}').on('change.td', function () {
      let newDate = Date.parse(timePicker.viewDate)

      $('#alt-item-{$fieldName}').val(newDate / 1000).toString()
    })
  });
</script>