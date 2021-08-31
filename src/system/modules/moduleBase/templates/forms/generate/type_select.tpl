{if isset($isMultiple) && $isMultiple}
    {assign var="isMultiple" value=true}
{else}
    {assign var="isMultiple" value=false}
{/if}
<select
        {if $isMultiple}multiple {/if}
        id="item-{$fieldName}"
        name="item[{$fieldName}]{if $isMultiple}[]{/if}"
        class="form-control"
        {if isset($field.options.required) && $field.options.required} required{/if}
        {if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key']} disabled{/if}
        {if isset($readonly) && $readonly} disabled{/if}
>
    {stripe}
    {if !$isMultiple}
        <option value="">Выбрать значение...</option>
    {/if}
    {assign var="relationMapper" value=$_entity->getMapper()->getRelationMapper($fieldName)}
    {foreach from=$relationMapper->getAllBy([]) item='optionItem'}
        {assign var="itemValue" value=$optionItem->getFieldValue($_entity->getMapper()->getRelationField($fieldName))}
        {if $isMultiple}
            {if isset($item) && in_array($itemValue, $item->getFieldValue($fieldName))}
                {assign var="selected" value=true}
            {else}
                {assign var="selected" value=false}
            {/if}
        {else}
            {if isset($item) && $item->getFieldValue($fieldName) == $itemValue}
                {assign var="selected" value=true}
            {else}
                {assign var="selected" value=false}
            {/if}
        {/if}
        <option value="{$itemValue|htmlspecialchars}"{if $selected} selected="selected"{/if}>
            {$optionItem->getTitleValue()}
        </option>
        {foreachelse}
        {if isset($item) && !$isMultiple}
            <option value="{$item->getFieldValue($fieldName)|htmlspecialchars}" selected="selected">{$item->getFieldValue($fieldName)}</option>
        {/if}
    {/foreach}
    {/stripe}
</select>