<input
        type="password"
        class="form-control"
        id="item-{$fieldName}"
        name="item[{$fieldName}]"
        value="{if isset($item)}{$item->getFieldValue($fieldName)|htmlspecialchars}{/if}"
        {if isset($field.options.required) && $field.options.required} required{/if}
        {if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key'] || isset($readonly) && $readonly} disabled{/if}
>