// Opt in only on an approved, migrated fixture installation with database authentication.
// This permits changing gutmal's profile and temporarily adding/removing their testing membership.
// Record the original values before running: browser/runner termination can prevent every cleanup hook.
const describeWithFixtureMutations = Cypress.env('allowStudentContactFixtureMutations') === true ? describe : describe.skip;
describeWithFixtureMutations('Student contact information synchronization', { retries: 0 }, () => {
    let original;
    let enrollmentAttempted;
    const studentId = 'gutmal';
    const fields = ['linkedin_username', 'github_username', 'personal_webpage'];
    const values = {
        linkedin_username: 'student-contact-test',
        github_username: 'student-contact-test',
        personal_webpage: 'https://example.com/portfolio?a=1&b=2',
    };
    const empty = Object.fromEntries(fields.map((field) => [field, '']));
    const login = (username) => {
        cy.logout();
        cy.login(username);
    };

    const visitRoster = (courseName) => {
        // The rendered roster does not mean userform.js has finished its AJAX request.
        // Wait before leaving the page so logout cannot invalidate that request's session.
        cy.intercept({ method: 'GET', pathname: `**/${courseName}/user_information`, times: 1 }).as('rosterUserInfo');
        cy.visit([courseName, 'users']);
        cy.wait('@rosterUserInfo').then(({ response }) => {
            expect(response, `${courseName} user_information response`).to.exist;
            expect(response.statusCode, `${courseName} user_information HTTP status`).to.equal(200);
            if (typeof response.body === 'string') {
                expect(response.body.trim(), `${courseName} user_information must return a JSON object, not an HTML page`).to.match(/^\{/);
            }
            const body = typeof response.body === 'string' ? JSON.parse(response.body) : response.body;
            expect(body).to.have.property('status', 'success');
            expect(body.data).to.be.an('object').and.to.have.property(studentId);
        });
        // This option is added by the AJAX success callback after parsing the response.
        cy.get(`#possible_users option[value="${studentId}"]`).should('exist');
    };

    const updateProfile = (data) => {
        cy.visit('/user_profile');
        const privacyNotice = 'Instructors from all your past, present, and future courses may access this information.';
        cy.get('#contact-information > p').should('have.text', 'Share your professional profiles or portfolio to help instructors stay in touch.');
        cy.get('#contact-information button').should('have.length', 1);
        cy.get('[data-testid="edit-contact-info-button"]').should('have.class', 'icon')
            .and('have.attr', 'aria-label', 'Edit contact information').click();
        cy.get('#edit-contact-info-form').should('be.visible')
            .and('contain.text', 'You can optionally share your LinkedIn profile, GitHub account, or personal website with your instructors. These links can help instructors stay in touch with you and follow your work after your courses end.')
            .and('contain.text', privacyNotice)
            .and('contain.text', 'All fields are optional. You can update your information at any time or leave a field empty to remove it.');
        fields.forEach((field) => {
            const selector = `#${field.replaceAll('_', '-')}-change`;
            cy.get(selector).clear();
            if (data[field]) {
                cy.get(selector).type(data[field]);
            }
        });
        cy.intercept('POST', '**/user_profile/change_contact_info').as('saveContact');
        cy.get('#edit-contact-info-form input[type="submit"]').click();
        cy.wait('@saveContact').its('response.body').then((body) => {
            const response = typeof body === 'string' ? JSON.parse(body) : body;
            expect(response.status).to.equal('success');
        });
        cy.get('#edit-contact-info-form').should('not.be.visible');
        cy.reload();
        fields.forEach((field) => {
            cy.get(`#${field.replaceAll('_', '-')}-row .contact-value`).should('have.text', data[field]);
        });
    };

    const expectRoster = (courseName, expected) => {
        visitRoster(courseName);
        cy.get('#download_info_json_id').invoke('val').then((json) => {
            const student = JSON.parse(json).find((user) => user.user_id === studentId);
            expect(student).to.exist;
            fields.forEach((field) => expect(student[field]).to.equal(expected[field]));
        });
    };

    beforeEach(() => {
        original = undefined;
        enrollmentAttempted = false;
        login('instructor');
        visitRoster('sample');
        cy.get(`[data-testid="user-row-${studentId}"]`).should('exist');
        login('instructor2');
        visitRoster('testing');
        cy.get('[data-testid="student-table"]').should('be.visible');
        cy.get(`[data-testid="user-row-${studentId}"]`).should('not.exist');

        login(studentId);
        cy.visit('/user_profile');
        const saved = {};
        fields.forEach((field) => {
            cy.get(`#${field.replaceAll('_', '-')}-change`).invoke('val').then((value) => saved[field] = value);
        });
        cy.then(() => original = saved);
    });

    // A parent hook is still attempted when the nested membership hook fails. Hooks at
    // the same suite level can be skipped. No retries: never recapture a failed restore.
    afterEach('restore the original contact information', () => {
        if (original) {
            login(studentId);
            updateProfile(original);
        }
    });

    describe('with a temporary testing membership', () => {
        afterEach('remove and verify the temporary testing membership', () => {
            if (enrollmentAttempted) {
                login('instructor2');
                visitRoster('testing');
                cy.get('[data-testid="student-table"]').should('be.visible').then(($table) => {
                    if ($table.find(`[data-testid="delete-student-${studentId}-button"]`).length > 0) {
                        cy.intercept('POST', '**/delete_user').as('removeMembership');
                        cy.get(`[data-testid="delete-student-${studentId}-button"]`).click();
                        cy.get('[data-testid="confirm-delete-button"]').click();
                        cy.wait('@removeMembership');
                    }
                });
                // Always check a fresh server roster, even when there was no delete button.
                visitRoster('testing');
                cy.get('#download_info_json_id').invoke('val').then((json) => {
                    const enrolled = JSON.parse(json).some((user) => user.user_id === studentId);
                    expect(enrolled, `Cleanup incomplete: ${studentId} is still enrolled in testing; manual recovery is required`).to.equal(false);
                });
            }
        });

        it('syncs saved values to existing and new memberships, then updates and clears both', () => {
            login(studentId);
            updateProfile(values);
            login('instructor');
            expectRoster('sample', values);

            login('instructor2');
            visitRoster('testing');
            cy.get('[data-testid="new-student-form-btn"]').click();
            cy.get('[data-testid="user-id-input"]').type(studentId);
            cy.get('[data-testid="user-id-input"]').blur();
            cy.get('#user_givenname').should('not.have.value', '');
            cy.get('[data-testid="password-input"]').type(studentId);
            cy.then(() => enrollmentAttempted = true);
            cy.get('[data-testid="submit-user-form-button"]').click();
            cy.get('[data-testid="popup-message"]').should('contain.text', `Existing Submitty user '${studentId}' added`);
            expectRoster('testing', values);

            const updated = { ...values, github_username: 'updated-contact-test' };
            login(studentId);
            updateProfile(updated);
            login('instructor');
            expectRoster('sample', updated);
            login('instructor2');
            expectRoster('testing', updated);

            login(studentId);
            updateProfile(empty);
            login('instructor');
            expectRoster('sample', empty);
            login('instructor2');
            expectRoster('testing', empty);
        });
    });
});
