<input type="radio"
        id="item-{$fieldName}"
        name="item[{$fieldName}]"
        {if isset($item)}{if $item->getFieldValue($fieldName)} checked{/if}{/if}
        {if isset($field.options.required) && $field.options.required} required{/if}
        {if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key'] || isset($readonly) && $readonly} disabled{/if}
>