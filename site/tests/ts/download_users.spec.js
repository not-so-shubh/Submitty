import { readFileSync } from 'fs';
import vm from 'vm';
import $ from 'jquery';

// server.js is a legacy global script. Exercise the actual download function in jsdom.
const source = readFileSync('public/js/server.js', 'utf8');
const start = source.indexOf('function downloadCSV(');
const downloadCSV = vm.runInNewContext(`${source.slice(start, source.indexOf('\n/**', start))}\ndownloadCSV`, { $, document });
const roundTrips = JSON.parse(readFileSync('tests/data/roster_hyphen_roundtrip.json', 'utf8'));
const spreadsheetRoundTrips = JSON.parse(readFileSync('tests/data/roster_spreadsheet_roundtrip.json', 'utf8'));

describe('Manage Users CSV download', () => {
    let downloaded;
    let filename;

    beforeEach(() => {
        document.body.innerHTML = '<input id="download_info_json_id" data-course="sample" data-semester="f26">'
            + '<div id="download-form"><input type="checkbox" value="1" checked><input type="checkbox" value="instructor" checked></div>';
        downloaded = '';
        jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
            downloaded = decodeURIComponent(this.getAttribute('href').split(',').slice(1).join(','));
            filename = this.getAttribute('download');
        });
    });

    afterEach(() => {
        jest.restoreAllMocks();
    });

    const setUsers = (users) => $('#download_info_json_id').val(JSON.stringify(users));

    test('appends contact fields and retains section filtering, deduplication, and filename', () => {
        setUsers([
            { user_id: 'student', reg_section: '1', linkedin_username: 'student-name', github_username: 'student-code', personal_webpage: 'https://example.com' },
            { user_id: 'other', reg_section: '2' },
            { user_id: 'teacher', reg_section: '1', group: 'Instructor' },
        ]);
        downloadCSV('user');
        expect(downloaded.split('\n')[0]).toBe('Given Name,Family Name,User ID,Email,Secondary Email,UTC Offset,Time Zone,Registration Section,Registration Subsection,Rotation Section,Group,LinkedIn Username,GitHub Username,Personal Webpage');
        expect(downloaded).toContain(',student,');
        expect(downloaded).toContain(',student-name,student-code,https://example.com\n');
        expect(downloaded).not.toContain(',other,');
        expect(downloaded.match(/,teacher,/g)).toHaveLength(1);
        expect(filename).toBe('f26_sample_users_data.csv');
    });

    test('escapes commas, quotes, CR and LF and keeps optional blanks', () => {
        setUsers([{ user_id: 'student', reg_section: '1', given_name: 'A,"B"\r\nC', personal_webpage: 'https://example.com/?a=1,2' }]);
        downloadCSV('user');
        expect(downloaded).toContain('"A,""B""\r\nC",,student,');
        expect(downloaded).toContain(',,,"https://example.com/?a=1,2"\n');
    });

    test.each(['=1+1', '+SUM(1)', '-1+2', '@SUM(1)', '  =1+1', '\u0000=1+1', '\tformula', '\rformula', '\nformula'])('neutralizes spreadsheet formulas: %p', (value) => {
        setUsers([{ user_id: 'student', reg_section: '1', given_name: value }]);
        downloadCSV('user');
        expect(downloaded).toContain(`'${value}`);
    });

    test('keeps the existing grader column set and registration quoting', () => {
        setUsers([{ user_id: 'teacher', reg_section: 'All', group: 'Instructor' }]);
        downloadCSV('grader');
        expect(downloaded.split('\n')[0]).toBe('Given Name,Family Name,User ID,Email,Secondary Email,UTC Offset,Time Zone,Registration Section,Registration Subsection,Rotation Section,Group');
        expect(downloaded).toContain(',"All","",,Instructor\n');
        expect(downloaded.match(/,teacher,/g)).toHaveLength(1);
    });

    test.each(roundTrips)('exports protected identifiers and sections for $code: $user.user_id', ({ code, user, csv }) => {
        $('#download-form').append($('<input type="checkbox" checked>').val(user.reg_section.split(',')[0]));
        setUsers([user]);
        downloadCSV(code);
        // PHP controller tests import these exact bytes, including the protective apostrophes.
        expect(downloaded).toBe(csv);
    });

    test.each(spreadsheetRoundTrips)('preserves original roster values: $name', ({ code, user, csv }) => {
        $('#download-form').append($('<input type="checkbox" checked>').val(user.reg_section.split(',')[0]));
        setUsers([user]);
        downloadCSV(code);
        // These same CSV bytes pass through the real PHP parser and uploadUserList().
        expect(downloaded).toBe(csv);
    });

    test('escapes original apostrophes in every row of a marked export', () => {
        const literal = { ...spreadsheetRoundTrips[1].user, user_id: 'literal' };
        $('#download-form').append('<input type="checkbox" value="-section" checked>');
        setUsers([spreadsheetRoundTrips[0].user, literal]);
        downloadCSV('user');
        const rows = downloaded.trimEnd().split('\n');
        expect(rows).toHaveLength(3);
        expect(rows[0]).toMatch(/,CSV Encoding$/u);
        expect(rows.slice(1).every((row) => row.endsWith(',apostrophe-v1'))).toBe(true);
        expect(rows[2]).toContain(`''-Jane,'''Doe,${literal.user_id},''=jane@example.com,'''jane@example.com,`);
    });

    test('unselected users do not change the export format', () => {
        setUsers([{ user_id: 'selected', reg_section: '1', given_name: 'Jane' }, { ...spreadsheetRoundTrips[0].user, reg_section: '2' }]);
        downloadCSV('user');
        expect(downloaded).not.toContain('CSV Encoding');
        expect(downloaded).not.toContain('\'-Jane');
    });

    test.each(['given_name', 'family_name', 'email', 'secondary_email'])('marks exports when only %s needs escaping', (key) => {
        const value = key.endsWith('_name') ? '-Jane' : '-jane@example.com';
        setUsers([{ user_id: 'student', reg_section: '1', given_name: 'Jane', family_name: 'Doe', email: 'jane@example.com', secondary_email: '', [key]: value }]);
        downloadCSV('user');
        expect(downloaded.split('\n')[0]).toMatch(/,CSV Encoding$/u);
        expect(downloaded).toContain(`'${value}`);
    });
});
