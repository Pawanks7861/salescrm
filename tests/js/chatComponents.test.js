// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));

import axios from 'axios';
import ChatComposer from '../../resources/js/Components/chat/ChatComposer.vue';
import ConversationList from '../../resources/js/Components/chat/ConversationList.vue';
import MessageBubble from '../../resources/js/Components/chat/MessageBubble.vue';
import NewChatModal from '../../resources/js/Components/chat/NewChatModal.vue';
import PriorityBanner from '../../resources/js/Components/chat/PriorityBanner.vue';
import { livePriority } from '../../resources/js/chat/live.js';
import { liveUnread } from '../../resources/js/notifications/notifier.js';

const TZ = 'Asia/Kolkata';
const config = { maxAttachmentKb: 10240, maxAttachments: 5, maxLength: 5000, allowedExtensions: ['pdf', 'png', 'jpg', 'zip'] };
const ModalStub = { props: ['show'], template: '<div v-if="show" data-testid="modal"><slot /></div>' };

beforeEach(() => {
    globalThis.route = vi.fn((name, params) => `/${name}${params !== undefined ? `/${typeof params === 'object' ? JSON.stringify(params) : params}` : ''}`);
    vi.clearAllMocks();
});
afterEach(() => {
    delete globalThis.route;
});

const conversations = [
    { id: 1, user: { id: 10, name: 'Priya Patel', online: true }, last_message: { text: 'See you', mine: false }, last_message_at: '2026-10-01T09:00:00Z', unread: 3 },
    { id: 2, user: { id: 11, name: 'Mehul Manager', online: false, last_seen_at: null }, last_message: { text: 'Done', mine: true }, last_message_at: '2026-09-29T09:00:00Z', unread: 0 },
    { id: 3, user: { id: 12, name: 'Anita Admin', online: false }, last_message: { text: 'x', deleted: true }, last_message_at: null, unread: 150 },
];

describe('ConversationList', () => {
    it('renders conversations with unread badges, online dots and previews', () => {
        const wrapper = mount(ConversationList, { props: { conversations, activeId: 2, tz: TZ } });

        expect(wrapper.findAll('[data-testid^="conversation-"]')).toHaveLength(3);
        expect(wrapper.get('[data-testid="unread-1"]').text()).toBe('3');
        expect(wrapper.find('[data-testid="unread-2"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="unread-3"]').text()).toBe('99+');
        expect(wrapper.findAll('[data-testid="online-dot"]')).toHaveLength(1);
        expect(wrapper.get('[data-testid="preview-2"]').text()).toBe('You: Done');
        expect(wrapper.get('[data-testid="preview-3"]').text()).toBe('This message was deleted.');
        expect(wrapper.get('[data-testid="conversation-2"]').attributes('aria-current')).toBe('true');
    });

    it('filters locally, emits select and new chat', async () => {
        const wrapper = mount(ConversationList, { props: { conversations, tz: TZ } });

        await wrapper.get('input[type="search"]').setValue('meh');
        expect(wrapper.findAll('[data-testid^="conversation-"]')).toHaveLength(1);

        await wrapper.get('[data-testid="conversation-2"]').trigger('click');
        await wrapper.get('[data-testid="new-chat"]').trigger('click');
        expect(wrapper.emitted('select')).toEqual([[2]]);
        expect(wrapper.emitted('new')).toHaveLength(1);
    });

    it('shows an empty state', () => {
        const wrapper = mount(ConversationList, { props: { conversations: [] } });
        expect(wrapper.get('[data-testid="no-conversations"]').text()).toContain('No conversations yet');
    });
});

describe('MessageBubble', () => {
    const base = { id: 7, conversation_id: 1, sender_id: 1, mine: true, body: 'Hello', deleted: false, edited: false, created_at: '2026-10-01T09:00:00Z', reply_to: null, attachments: [] };

    it('renders text safely as text, never as HTML', () => {
        const wrapper = mount(MessageBubble, { props: { message: { ...base, body: '<img src=x onerror=alert(1)><b>bold</b>' }, tz: TZ } });

        expect(wrapper.get('[data-testid="message-body"]').text()).toBe('<img src=x onerror=alert(1)><b>bold</b>');
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('b').exists()).toBe(false);
    });

    it('shows sent vs read status for my messages', () => {
        expect(mount(MessageBubble, { props: { message: base, otherLastReadId: 6 } }).find('[data-testid="status-sent"]').exists()).toBe(true);
        expect(mount(MessageBubble, { props: { message: base, otherLastReadId: 7 } }).find('[data-testid="status-read"]').exists()).toBe(true);
        expect(mount(MessageBubble, { props: { message: { ...base, mine: false }, otherLastReadId: 9 } }).find('[data-testid^="status-"]').exists()).toBe(false);
    });

    it('shows the edited label, reply quote and deleted placeholder', () => {
        const edited = mount(MessageBubble, { props: { message: { ...base, edited: true, reply_to: { id: 3, sender_id: 2, preview: 'Original question' } }, otherName: 'Priya' } });
        expect(edited.find('[data-testid="edited-label"]').exists()).toBe(true);
        expect(edited.get('[data-testid="reply-quote"]').text()).toContain('Original question');
        expect(edited.get('[data-testid="reply-quote"]').text()).toContain('Priya');

        const deleted = mount(MessageBubble, { props: { message: { ...base, deleted: true, body: null, attachments: [] } } });
        expect(deleted.text()).toContain('This message was deleted.');
        expect(deleted.find('[data-testid="message-body"]').exists()).toBe(false);
        expect(deleted.find('button[aria-label="Edit"]').exists()).toBe(false);
    });

    it('renders image thumbnails and document download links through the protected route', () => {
        const message = {
            ...base,
            body: null,
            attachments: [
                { id: 1, name: 'site.png', size: 2048, is_image: true, url: '/chat/attachments/1', preview_url: '/chat/attachments/1?inline=1' },
                { id: 2, name: 'quote.pdf', size: 1048576, is_image: false, url: '/chat/attachments/2', preview_url: null },
            ],
        };
        const wrapper = mount(MessageBubble, { props: { message } });

        expect(wrapper.get('img').attributes('src')).toBe('/chat/attachments/1?inline=1');
        const doc = wrapper.get('[data-testid="attachment-2"]');
        expect(doc.attributes('href')).toBe('/chat/attachments/2');
        expect(doc.text()).toContain('quote.pdf');
        expect(doc.text()).toContain('1.0 MB');
    });

    it('offers edit/delete only on my messages and emits actions', async () => {
        const mine = mount(MessageBubble, { props: { message: base } });
        await mine.get('button[aria-label="Edit"]').trigger('click');
        await mine.get('button[aria-label="Delete"]').trigger('click');
        await mine.get('button[aria-label="Reply"]').trigger('click');
        expect(mine.emitted('edit')[0][0].id).toBe(7);
        expect(mine.emitted('delete')[0][0].id).toBe(7);
        expect(mine.emitted('reply')[0][0].id).toBe(7);

        const theirs = mount(MessageBubble, { props: { message: { ...base, mine: false } } });
        expect(theirs.find('button[aria-label="Edit"]').exists()).toBe(false);
        expect(theirs.find('button[aria-label="Delete"]').exists()).toBe(false);
    });
});

describe('ChatComposer', () => {
    it('sends on Enter, keeps Shift+Enter for new lines and blocks empty messages', async () => {
        const wrapper = mount(ChatComposer, { props: { config } });
        const input = wrapper.get('[data-testid="composer-input"]');

        expect(wrapper.get('[data-testid="send-button"]').attributes('disabled')).toBeDefined();
        await input.trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('send')).toBeUndefined();

        await input.setValue('Hello team');
        await input.trigger('keydown', { key: 'Enter', shiftKey: true });
        expect(wrapper.emitted('send')).toBeUndefined();

        await input.trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('send')[0][0]).toEqual({ text: 'Hello team', files: [] });
    });

    it('validates picked files before upload', async () => {
        const wrapper = mount(ChatComposer, { props: { config } });
        const picker = wrapper.get('[data-testid="file-input"]');

        Object.defineProperty(picker.element, 'files', { value: [new File(['x'], 'virus.exe')], configurable: true });
        await picker.trigger('change');
        expect(wrapper.get('[data-testid="file-error"]').text()).toContain('not allowed');
        expect(wrapper.find('[data-testid="pending-files"]').exists()).toBe(false);

        Object.defineProperty(picker.element, 'files', { value: [new File(['%PDF'], 'quote.pdf', { type: 'application/pdf' })], configurable: true });
        await picker.trigger('change');
        expect(wrapper.get('[data-testid="pending-files"]').text()).toContain('quote.pdf');

        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('send')[0][0].files).toHaveLength(1);
    });

    it('shows reply and edit bars and a disabled reason', async () => {
        const reply = mount(ChatComposer, { props: { config, replyTo: { id: 3, body: 'Question?' }, replyAuthor: 'Priya' } });
        expect(reply.get('[data-testid="reply-bar"]').text()).toContain('Replying to Priya');

        const edit = mount(ChatComposer, { props: { config } });
        await edit.setProps({ editing: { id: 4, body: 'Old text' } });
        expect(edit.get('[data-testid="editing-bar"]').exists()).toBe(true);
        expect(edit.get('[data-testid="composer-input"]').element.value).toBe('Old text');
        await edit.get('form').trigger('submit');
        expect(edit.emitted('save-edit')[0][0]).toMatchObject({ text: 'Old text' });

        const disabled = mount(ChatComposer, { props: { config, disabled: true, disabledReason: 'This user is no longer active.' } });
        expect(disabled.get('[data-testid="composer-disabled"]').text()).toContain('no longer active');
        expect(disabled.find('[data-testid="composer-input"]').exists()).toBe(false);
    });

    it('throttles typing signals', async () => {
        const wrapper = mount(ChatComposer, { props: { config } });
        const input = wrapper.get('[data-testid="composer-input"]');
        await input.setValue('h');
        await input.setValue('he');
        await input.setValue('hel');
        expect(wrapper.emitted('typing')).toHaveLength(1);
    });
});

describe('NewChatModal', () => {
    it('searches users server-side and starts a conversation', async () => {
        axios.get.mockResolvedValue({ data: { users: [{ id: 10, name: 'Priya Patel', designation: 'Executive', online: true }] } });
        axios.post.mockResolvedValue({ data: { conversation_id: 42 } });

        const wrapper = mount(NewChatModal, { props: { show: false }, global: { stubs: { Modal: ModalStub } } });
        await wrapper.setProps({ show: true });
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith('/chat.users.index', { params: { q: undefined } });
        await wrapper.get('[data-testid="user-10"]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/chat.users.start/10');
        expect(wrapper.emitted('start')).toEqual([[42]]);
    });
});

describe('PriorityBanner', () => {
    const alerts = [
        { id: 5, title: 'Office closed', message: 'Work from home tomorrow.', sender: 'Anita Admin', sent_at: '2026-10-01T09:00:00Z', expires_at: null, read: false },
        { id: 6, title: 'Already seen', message: 'x', sender: 'Anita Admin', sent_at: '2026-10-01T08:00:00Z', expires_at: null, read: true },
    ];

    it('renders urgent banners and marks unseen ones read once', async () => {
        axios.post.mockResolvedValue({ data: { ok: true } });
        const wrapper = mount(PriorityBanner, { props: { alerts } });
        await flushPromises();

        expect(wrapper.findAll('[role="alert"]')).toHaveLength(2);
        expect(wrapper.get('[data-testid="priority-banner-5"]').text()).toContain('Urgent');
        expect(wrapper.get('[data-testid="priority-banner-5"]').text()).toContain('Office closed');
        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(axios.post).toHaveBeenCalledWith('/priority-broadcasts.read/5');

        await wrapper.setProps({ alerts: [...alerts] });
        await flushPromises();
        expect(axios.post).toHaveBeenCalledTimes(1);
    });

    it('acknowledges, removes the banner from the live list and refreshes the bell count', async () => {
        axios.post.mockResolvedValue({ data: { ok: true, notifications_unread: 0 } });
        liveUnread.value = 1;
        livePriority.value = [...alerts];
        const wrapper = mount(PriorityBanner, { props: { alerts } });

        const button = wrapper.get('[data-testid="priority-banner-5"]').findAll('button').find((b) => b.text().includes('Acknowledge'));
        await button.trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/priority-broadcasts.acknowledge/5', {}, { headers: { Accept: 'application/json' } });
        expect(livePriority.value.map((a) => a.id)).toEqual([6]);
        expect(liveUnread.value).toBe(0);
    });
});
