import { readFileSync } from 'fs';
import vm from 'vm';
import $ from 'jquery';

const source = readFileSync('public/js/user-profile.js', 'utf8');
const start = source.indexOf('function showUpdateContactInfoForm(');
const end = source.indexOf('\nfunction ', source.indexOf('function updateUserContactInfo(', start));
const fields = ['linkedin_username', 'github_username', 'personal_webpage'];

describe('Profile contact information popup', () => {
    let context;
    let handlers;

    beforeEach(() => {
        document.body.innerHTML = '<div id="linkedin-username-row"><span class="value contact-value">old-linkedin</span></div>'
            + '<div id="github-username-row"><span class="value contact-value">old-github</span></div>'
            + '<div id="personal-webpage-row"><span class="value contact-value">https://example.com/old</span></div>'
            + '<div class="popup-form" id="edit-contact-info-form" style="display:none">'
            + '<input id="linkedin-username-change"><input id="github-username-change"><input id="personal-webpage-change"></div>';
        context = {
            $, FormData, csrfToken: 'csrf-test', buildUrl: (parts) => `/${parts.join('/')}`,
            showPopup: jest.fn((selector) => $(selector).show()),
            closePopup: jest.fn((id) => $(`#${id}`).hide()),
            displaySuccessMessage: jest.fn(), displayErrorMessage: jest.fn(),
        };
        // Exercise the existing legacy functions without running unrelated profile initialization.
        handlers = vm.runInNewContext(`${source.slice(start, end)}\n({ showUpdateContactInfoForm, updateUserContactInfo })`, context);
        jest.spyOn($, 'ajax').mockImplementation(() => {});
    });

    afterEach(() => {
        jest.restoreAllMocks();
    });

    test('opens the existing three-field popup and focuses the first input', () => {
        handlers.showUpdateContactInfoForm();
        expect(context.showPopup).toHaveBeenCalledWith('#edit-contact-info-form');
        expect(document.activeElement.id).toBe('linkedin-username-change');
    });

    test.each([
        { linkedin_username: 'student-name', github_username: 'student-code', personal_webpage: 'https://example.com/?a=1&b=2' },
        { linkedin_username: '', github_username: '', personal_webpage: '' },
    ])('saves and displays all three values: %p', (values) => {
        for (const field of fields) {
            $(`#${field.replaceAll('_', '-')}-change`).val(values[field]);
        }
        expect(handlers.updateUserContactInfo()).toBe(false);
        const request = $.ajax.mock.calls[0][0];
        expect(request.url).toBe('/user_profile/change_contact_info');
        expect(request.type).toBe('POST');
        expect(request.data.get('csrf_token')).toBe('csrf-test');
        for (const field of fields) {
            expect(request.data.get(field)).toBe(values[field]);
        }
        request.success(JSON.stringify({ status: 'success', data: values }));
        for (const field of fields) {
            const id = field.replaceAll('_', '-');
            expect($(`#${id}-row .contact-value`).text()).toBe(values[field]);
            expect($(`#${id}-change`).val()).toBe(values[field]);
        }
        expect(context.closePopup).toHaveBeenCalledWith('edit-contact-info-form');
        expect(context.displaySuccessMessage).toHaveBeenCalledTimes(1);
    });

    test('retains displayed values when the server denies an edit', () => {
        const original = fields.map((field) => $(`#${field.replaceAll('_', '-')}-row .contact-value`).text());
        handlers.updateUserContactInfo();
        $.ajax.mock.calls[0][0].success(JSON.stringify({ status: 'error', message: 'Recorded student enrollment required' }));
        expect(context.displayErrorMessage).toHaveBeenCalledWith('Recorded student enrollment required');
        expect(context.closePopup).not.toHaveBeenCalled();
        expect(fields.map((field) => $(`#${field.replaceAll('_', '-')}-row .contact-value`).text())).toEqual(original);
    });
});
