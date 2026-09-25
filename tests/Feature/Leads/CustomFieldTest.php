<?php

use App\Models\Lead;
use App\Models\LeadCustomField;
use App\Models\LeadCustomFieldValue;
use App\Services\Leads\LeadConfigurationService;

beforeEach(function () {
    $this->org = salesOrg();
});

function makeField(array $attrs): LeadCustomField
{
    return app(LeadConfigurationService::class)->save(new LeadCustomField, array_merge(['is_active' => true, 'sort_order' => 10], $attrs));
}

test('admin with lead.configure manages custom fields; sales executives cannot', function () {
    $this->actingAs($this->org->rahul)->get('/admin/custom-fields')->assertForbidden();
    $this->actingAs($this->org->rahul)->post('/admin/custom-fields', ['name' => 'Budget', 'field_type' => 'number'])->assertForbidden();

    $this->actingAs($this->org->admin)->post('/admin/custom-fields', [
        'name' => 'Property Type', 'field_type' => 'dropdown', 'options' => ['Flat', 'Villa'], 'is_required' => true, 'is_active' => true,
    ])->assertRedirect();

    $field = LeadCustomField::sole();
    expect($field->slug)->toBe('property_type')->and($field->options())->toBe(['Flat', 'Villa']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'CONFIGURATION_CREATED', 'entity_type' => 'LeadCustomField']);
});

test('dropdown fields require options and type cannot change after creation', function () {
    $this->actingAs($this->org->admin)->post('/admin/custom-fields', ['name' => 'X', 'field_type' => 'dropdown'])->assertSessionHasErrors('options');

    $field = makeField(['name' => 'Budget', 'field_type' => 'number']);
    $this->actingAs($this->org->admin)->put("/admin/custom-fields/{$field->id}", ['name' => 'Budget', 'field_type' => 'text'])->assertSessionHasErrors('field_type');
});

test('custom field values are validated on the backend by type', function () {
    makeField(['name' => 'Budget', 'field_type' => 'number', 'validation_rules_json' => ['min' => 1000]]);
    makeField(['name' => 'Property Type', 'field_type' => 'dropdown', 'options_json' => ['Flat', 'Villa'], 'is_required' => true]);
    makeField(['name' => 'Visit Date', 'field_type' => 'date']);
    makeField(['name' => 'Amenities', 'field_type' => 'multiselect', 'options_json' => ['Pool', 'Gym']]);

    $this->actingAs($this->org->rahul)->post('/leads', leadPayload(['custom_fields' => [
        'budget' => 'abc', 'property_type' => 'Castle', 'visit_date' => '31-12-2026', 'amenities' => ['Pool', 'Helipad'],
    ]]))->assertSessionHasErrors(['custom_fields.budget', 'custom_fields.property_type', 'custom_fields.visit_date', 'custom_fields.amenities.1']);

    $this->actingAs($this->org->rahul)->post('/leads', leadPayload(['custom_fields' => ['budget' => 500]]))
        ->assertSessionHasErrors(['custom_fields.budget', 'custom_fields.property_type']);

    expect(Lead::count())->toBe(0);
});

test('valid custom field values are stored and updates are audited', function () {
    makeField(['name' => 'Budget', 'field_type' => 'number']);
    makeField(['name' => 'Amenities', 'field_type' => 'multiselect', 'options_json' => ['Pool', 'Gym']]);
    makeField(['name' => 'Loan Needed', 'field_type' => 'checkbox']);

    $this->actingAs($this->org->rahul)->post('/leads', leadPayload(['custom_fields' => [
        'budget' => '2500000', 'amenities' => ['Gym', 'Pool'], 'loan_needed' => true, 'unknown_field' => 'ignored',
    ]]))->assertRedirect();

    $lead = Lead::sole();
    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->count())->toBe(3);

    $this->actingAs($this->org->rahul)->put("/leads/{$lead->id}", leadPayload(['custom_fields' => ['budget' => '3000000']]))->assertRedirect();

    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_CUSTOM_FIELD_UPDATED', 'entity_id' => $lead->id]);
    $display = collect($this->actingAs($this->org->rahul)->get("/leads/{$lead->id}")->inertiaProps('customFields'))->keyBy('slug');
    expect($display['budget']['display'])->toBe('3000000')
        ->and($display['amenities']['display'])->toBe('Pool, Gym')
        ->and($display['loan_needed']['display'])->toBe('Yes');
});

test('fields holding data cannot be deleted', function () {
    $field = makeField(['name' => 'Budget', 'field_type' => 'number']);
    $lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    LeadCustomFieldValue::create(['lead_id' => $lead->id, 'lead_custom_field_id' => $field->id, 'value' => '1']);

    $this->actingAs($this->org->admin)->delete("/admin/custom-fields/{$field->id}")->assertSessionHasErrors('record');
    expect(LeadCustomField::count())->toBe(1);
});
