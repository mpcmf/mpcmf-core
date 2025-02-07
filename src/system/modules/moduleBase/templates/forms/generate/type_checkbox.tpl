{assign var="disabled" value=isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key'] || isset($readonly) && $readonly}
<div class="form-check">
{if !$disabled}
    <input type="hidden"
           id="item-{$fieldName}-hidden"
           class="form-check-input"
           name="item[{$fieldName}]" value="">
{/if}
    <input type="checkbox"
           id="item-{$fieldName}"
           class="form-check-input"
           name="item[{$fieldName}]"
            {if isset($item)}{if $item->getFieldValue($fieldName)} checked{/if}{/if}
            {if isset($field.options.required) && $field.options.required} required{/if}
            {if $disabled} disabled{/if}
    >
</div>