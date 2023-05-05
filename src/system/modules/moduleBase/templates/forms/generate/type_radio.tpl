<div class="form-check">
    <input type="radio"
           id="item-{$fieldName}"
           class="form-check-input"
           name="item[{$fieldName}]"
            {if isset($item)}{if $item->getFieldValue($fieldName)} checked{/if}{/if}
            {if isset($field.options.required) && $field.options.required} required{/if}
            {if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key'] || isset($readonly) && $readonly} disabled{/if}
    >
</div>