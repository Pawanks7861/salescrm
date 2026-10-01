// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ submitted: [], router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), on: vi.fn(() => () => {}) } }));

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    const useForm = (initial) => {
        const copy = () => JSON.parse(JSON.stringify(initial));
        let transform = (data) => data;
        const form = reactive({
            ...copy(),
            errors: {},
            processing: false,
            transform(fn) {
                transform = fn;
                return form;
            },
            reset() {
                Object.assign(form, copy());
            },
            clearErrors() {
                form.errors = {};
            },
        });
        const data = () => Object.fromEntries(Object.keys(initial).map((k) => [k, JSON.parse(JSON.stringify(form[k]))]));
        for (const method of ['post', 'put']) {
            form[method] = (url, options) => {
                inertia.submitted.push({ method, url, data: transform(data()) });
                options?.onSuccess?.();
            };
        }
        return form;
    };
    return {
        router: inertia.router,
        useForm,
        usePage: () => ({ props: {} }),
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    };
});

import axios from 'axios';
import AddTrainersModal from '../../resources/js/Components/batches/AddTrainersModal.vue';
import TrainerSelector from '../../resources/js/Components/batches/TrainerSelector.vue';
import { useConfirm } from '../../resources/js/Composables/useConfirm.js';
import BatchForm from '../../resources/js/Pages/Batches/Form.vue';
import BatchIndex from '../../resources/js/Pages/Batches/Index.vue';
import BatchShow from '../../resources/js/Pages/Batches/Show.vue';

const rahul = { id: 31, name: 'Rahul Sharma', role: 'Trainer', designation: 'Senior Trainer', active: true };
const priya = { id: 32, name: 'Priya Patel', role: 'Trainer', designation: null, active: true };
const amit = { id: 33, name: 'Amit Shah', role: 'Trainer', designation: null, active: true };

const ModalStub = { props: ['show'], template: '<div v-if="show" data-testid="modal"><slot /></div>' };
const LayoutStub = { template: '<div><slot /></div>' };
const PageHeaderStub = { props: ['title', 'subtitle'], template: '<header><h1>{{ title }}</h1><slot name="breadcrumb" /><slot name="actions" /></header>' };
const pageStubs = { AppLayout: LayoutStub, PageHeader: PageHeaderStub, Modal: ModalStub, LeadSelector: true, AddLeadsModal: true, UiPagination: true, FilterBar: { template: '<div><slot /></div>' } };
const pageGlobal = () => ({ stubs: pageStubs, mocks: { route: globalThis.route } });

beforeEach(() => {
    globalThis.route = vi.fn((name, params) => `/${name}${params !== undefined ? `/${typeof params === 'object' ? JSON.stringify(params) : params}` : ''}`);
    vi.clearAllMocks();
    vi.useFakeTimers();
    inertia.submitted.length = 0;
    axios.get.mockResolvedValue({ data: { results: [rahul, priya, amit] } });
});
afterEach(() => {
    vi.useRealTimers();
    delete globalThis.route;
});

const checkboxFor = (wrapper, name) => wrapper.findAll('li label').find((l) => l.text().includes(name)).get('input[type="checkbox"]');

describe('TrainerSelector', () => {
    it('loads active trainers from the server and searches server-side', async () => {
        const wrapper = mount(TrainerSelector, { props: { modelValue: [] } });
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith('/batches.trainer-search', { params: {} });
        expect(wrapper.findAll('li label')).toHaveLength(3);
        expect(wrapper.text()).toContain('Trainer · Senior Trainer');

        await wrapper.get('input[type="search"]').setValue('  Priya ');
        vi.advanceTimersByTime(300);
        await flushPromises();
        expect(axios.get).toHaveBeenLastCalledWith('/batches.trainer-search', { params: { q: 'Priya' } });
    });

    it('selects several trainers and shows them as removable chips', async () => {
        const wrapper = mount(TrainerSelector, { props: { modelValue: [], 'onUpdate:modelValue': (v) => wrapper.setProps({ modelValue: v }) } });
        await flushPromises();

        await checkboxFor(wrapper, 'Rahul Sharma').setValue(true);
        await checkboxFor(wrapper, 'Priya Patel').setValue(true);
        expect(wrapper.props('modelValue').map((t) => t.id)).toEqual([31, 32]);

        const chips = wrapper.get('[data-testid="selected-trainers"]');
        expect(chips.text()).toContain('Rahul Sharma');
        expect(chips.text()).toContain('Priya Patel');

        await chips.get('button[aria-label="Remove Rahul Sharma"]').trigger('click');
        expect(wrapper.props('modelValue').map((t) => t.id)).toEqual([32]);
    });

    it('hides excluded trainers and marks inactive selected ones', async () => {
        const wrapper = mount(TrainerSelector, { props: { modelValue: [{ ...amit, active: false }], excludeIds: [31] } });
        await flushPromises();

        expect(wrapper.findAll('li label').map((l) => l.text())).not.toContain(expect.stringContaining('Rahul'));
        expect(wrapper.get('[data-testid="selected-trainers"]').text()).toContain('Inactive');
    });

    it('only allows removal when adding is not allowed (archived batch)', async () => {
        const wrapper = mount(TrainerSelector, { props: { modelValue: [rahul], allowAdd: false } });
        await flushPromises();

        expect(axios.get).not.toHaveBeenCalled();
        expect(wrapper.find('input[type="search"]').exists()).toBe(false);
        expect(wrapper.find('button[aria-label="Remove Rahul Sharma"]').exists()).toBe(true);
    });
});

describe('AddTrainersModal', () => {
    it('offers only unassigned trainers and posts the selected ids', async () => {
        const batch = { id: 5, name: 'October Batch', trainers: [rahul] };
        const wrapper = mount(AddTrainersModal, { props: { show: false, batch }, global: { stubs: { Modal: ModalStub } } });
        await wrapper.setProps({ show: true });
        await flushPromises();

        expect(wrapper.text()).not.toContain('Rahul Sharma');
        const submit = wrapper.get('button[type="submit"]');
        expect(submit.attributes('disabled')).toBeDefined();

        await checkboxFor(wrapper, 'Priya Patel').setValue(true);
        await checkboxFor(wrapper, 'Amit Shah').setValue(true);
        expect(submit.text()).toBe('Add 2 trainers');
        await wrapper.get('form').trigger('submit');

        expect(inertia.submitted).toEqual([{ method: 'post', url: '/batches.trainers.store/5', data: { trainer_ids: [32, 33] } }]);
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});

describe('Batch form', () => {
    const base = { statuses: [{ value: 'active', label: 'Active' }], leadOptions: { statuses: [], sources: [] } };

    it('sends trainer_ids with a new batch, never the trainer objects', async () => {
        const wrapper = mount(BatchForm, { props: { ...base, batch: null, can: { manageLeads: false, manageTrainers: true } }, global: pageGlobal() });
        await flushPromises();

        await wrapper.get('input[maxlength="150"]').setValue('October Batch');
        await checkboxFor(wrapper, 'Rahul Sharma').setValue(true);
        await checkboxFor(wrapper, 'Priya Patel').setValue(true);
        await wrapper.get('form').trigger('submit');

        expect(inertia.submitted).toHaveLength(1);
        expect(inertia.submitted[0]).toMatchObject({ method: 'post', url: '/batches.store', data: { name: 'October Batch', trainer_ids: [31, 32] } });
        expect(inertia.submitted[0].data).not.toHaveProperty('trainers');
    });

    it('has no trainer field or trainer_ids without batch.manage_trainers', async () => {
        const wrapper = mount(BatchForm, { props: { ...base, batch: null, can: { manageLeads: false, manageTrainers: false } }, global: pageGlobal() });
        await flushPromises();

        expect(wrapper.text()).not.toContain('Trainer(s)');
        await wrapper.get('input[maxlength="150"]').setValue('Plain batch');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted[0].data).not.toHaveProperty('trainer_ids');
    });

    it('shows current trainers on edit and sends the updated list', async () => {
        const batch = { id: 9, batch_number: 'BAT-2026-000009', name: 'October Batch', description: null, start_date: null, end_date: null, status: 'active', archived: false, trainers: [rahul, priya] };
        const wrapper = mount(BatchForm, { props: { ...base, batch, can: { manageLeads: false, manageTrainers: true } }, global: pageGlobal() });
        await flushPromises();

        await wrapper.get('button[aria-label="Remove Rahul Sharma"]').trigger('click');
        await checkboxFor(wrapper, 'Amit Shah').setValue(true);
        await wrapper.get('form').trigger('submit');

        expect(inertia.submitted[0]).toMatchObject({ method: 'put', url: '/batches.update/9', data: { name: 'October Batch', trainer_ids: [32, 33] } });
    });
});

describe('Batch detail trainers', () => {
    const page = (trainers, can) => ({
        batch: { id: 5, batch_number: 'BAT-2026-000005', name: 'October Batch', status: { value: 'active', label: 'Active', color: 'green' }, start_date: null, end_date: null, creator: null, created_at: null, description: null, trainers },
        summary: { total: 0, statuses: [] },
        leads: { data: [], links: [] },
        filters: {},
        options: { statuses: [], activeStatuses: [], sources: [], users: [] },
        can: { update: false, archive: false, restore: false, delete: false, addLeads: false, removeLeads: false, filterByUser: false, addTrainers: false, removeTrainers: false, ...can },
    });

    it('lists trainers and shows add/remove only with permission', () => {
        const viewer = mount(BatchShow, { props: page([rahul, priya], {}), global: pageGlobal() });
        const section = viewer.get('[data-testid="batch-trainers"]');
        expect(section.text()).toContain('Trainers');
        expect(section.text()).toContain('Rahul Sharma');
        expect(section.text()).toContain('Priya Patel');
        expect(section.text()).not.toContain('Add Trainer');
        expect(section.find('button[aria-label="Remove Rahul Sharma"]').exists()).toBe(false);

        const manager = mount(BatchShow, { props: page([rahul], { addTrainers: true, removeTrainers: true }), global: pageGlobal() });
        expect(manager.get('[data-testid="batch-trainers"]').text()).toContain('Add Trainer');
        expect(manager.find('button[aria-label="Remove Rahul Sharma"]').exists()).toBe(true);
    });

    it('says "Not assigned" when the batch has no trainer', () => {
        const wrapper = mount(BatchShow, { props: page([], {}), global: pageGlobal() });
        expect(wrapper.get('[data-testid="batch-trainers"]').text()).toContain('Not assigned');
    });

    it('confirms before removing only the trainer assignment', async () => {
        const wrapper = mount(BatchShow, { props: page([rahul], { removeTrainers: true }), global: pageGlobal() });
        const { state, settle } = useConfirm();

        await wrapper.get('button[aria-label="Remove Rahul Sharma"]').trigger('click');
        expect(state.title).toBe('Remove Rahul Sharma from this Batch?');
        expect(state.message).toContain('This will only remove the Trainer assignment.');
        expect(state.message).toContain('No Leads or other Batch data will be changed.');
        settle(true);
        await flushPromises();

        expect(inertia.router.delete).toHaveBeenCalledWith('/batches.trainers.destroy/[5,31]', { preserveScroll: true });
    });
});

describe('Batch list trainers', () => {
    const list = (rows, extra = {}) => ({
        batches: { data: rows, total: rows.length, links: [] },
        filters: {},
        statuses: [],
        can: { create: false },
        ...extra,
    });
    const row = (id, trainers) => ({ id, batch_number: `BAT-${id}`, name: `Batch ${id}`, description: null, start_date: null, end_date: null, leads_count: 0, status: { value: 'active', label: 'Active', color: 'green' }, creator: null, created_at: null, trainers, can: {} });

    it('shows one trainer by name and several as "first +N" with every name in the tooltip', () => {
        const wrapper = mount(BatchIndex, { props: list([row(1, [rahul]), row(2, [rahul, priya, amit]), row(3, [])]), global: pageGlobal() });
        const cells = wrapper.findAll('[data-testid="trainer-cell"]');

        expect(cells[0].text()).toBe('Rahul Sharma');
        expect(cells[1].findAll('span').map((s) => s.text())).toEqual(['Rahul Sharma', '+2']);
        expect(cells[1].attributes('title')).toBe('Rahul Sharma\nPriya Patel\nAmit Shah');
        expect(cells[2].text()).toBe('—');
    });

    it('offers a trainer filter, with "My batches" for trainers', () => {
        const trainer = mount(BatchIndex, { props: list([], { isTrainer: true, trainerOptions: [{ id: 31, name: 'Rahul Sharma' }] }), global: pageGlobal() });
        const options = trainer.get('select[aria-label="Trainer"]').findAll('option').map((o) => o.text());
        expect(options).toEqual(['Any trainer', 'My batches', 'Rahul Sharma']);

        const manager = mount(BatchIndex, { props: list([], { isTrainer: false, trainerOptions: [{ id: 31, name: 'Rahul Sharma' }] }), global: pageGlobal() });
        expect(manager.get('select[aria-label="Trainer"]').text()).not.toContain('My batches');
    });
});
