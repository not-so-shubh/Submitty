<?php

namespace tests\app\controllers;

use app\controllers\UserProfileController;
use app\libraries\Core;
use app\libraries\database\DatabaseQueries;
use app\libraries\Output;
use app\models\Config;
use app\models\Locale;
use app\models\User;
use app\views\UserProfileView;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class UserProfileControllerTester extends TestCase {
    protected function tearDown(): void {
        $_POST = [];
    }

    public function testUpdateOwnContactInfoAndClear(): void {
        $core = $this->createMock(Core::class);
        $user = new User($core);
        $user->setId('student');
        $core->method('getUser')->willReturn($user);
        $queries = $this->createMock(DatabaseQueries::class);
        $core->method('getQueries')->willReturn($queries);
        $queries->method('hasStudentEnrollment')->with('student')->willReturn(true);
        $queries->expects($this->exactly(2))->method('updateUserContactInfo')->with($this->identicalTo($user));
        $controller = new UserProfileController($core);
        $_POST = [
            'user_id' => 'another-student',
            'linkedin_username' => ' student-name ',
            'github_username' => ' student-code ',
            'personal_webpage' => ' https://example.com/portfolio?a=1&b=2 '
        ];
        $this->assertSame('success', $controller->changeContactInfo()->json['status']);
        $this->assertSame('student', $user->getId());
        $this->assertSame('student-name', $user->getLinkedinUsername());
        $this->assertSame('student-code', $user->getGithubUsername());
        $this->assertSame('https://example.com/portfolio?a=1&b=2', $user->getPersonalWebpage());

        $_POST = ['linkedin_username' => '', 'github_username' => ' ', 'personal_webpage' => ''];
        $response = $controller->changeContactInfo()->json;
        $this->assertSame('success', $response['status']);
        $this->assertSame(['linkedin_username' => '', 'github_username' => '', 'personal_webpage' => ''], $response['data']);
        $this->assertSame('', $user->getLinkedinUsername());
        $this->assertSame('', $user->getGithubUsername());
        $this->assertSame('', $user->getPersonalWebpage());
    }

    public function testInvalidContactInfoDoesNotPartiallyUpdate(): void {
        $core = $this->createMock(Core::class);
        $user = new User($core);
        $user->setId('student');
        $user->setLinkedinUsername('original-name');
        $core->method('getUser')->willReturn($user);
        $queries = $this->createMock(DatabaseQueries::class);
        $core->method('getQueries')->willReturn($queries);
        $queries->method('hasStudentEnrollment')->with('student')->willReturn(true);
        $queries->expects($this->never())->method('updateUserContactInfo');
        $controller = new UserProfileController($core);
        $posts = [
            ['linkedin_username' => 'new-name', 'github_username' => 'bad--name', 'personal_webpage' => ''],
            ['linkedin_username' => 'new-name', 'github_username' => '', 'personal_webpage' => 'javascript:alert(1)'],
            ['linkedin_username' => [], 'github_username' => '', 'personal_webpage' => ''],
            ['linkedin_username' => '', 'github_username' => '']
        ];
        foreach ($posts as $post) {
            $_POST = $post;
            $this->assertSame('error', $controller->changeContactInfo()->json['status']);
            $this->assertSame('original-name', $user->getLinkedinUsername());
        }
    }

    public static function personalWebpagePortProvider(): array {
        return [
            'empty HTTP port' => ['http://example.com:/', false],
            'empty HTTPS port' => ['https://example.com:/', false],
            'empty HTTP port before path' => ['http://example.com:/path', false],
            'empty HTTPS port before path' => ['https://example.com:/path', false],
            'empty port before query' => ['https://example.com:?q=1', false],
            'empty port before fragment' => ['https://example.com:#portfolio', false],
            'empty port at end' => ['https://example.com:', false],
            'empty IPv6 port before path' => ['https://[::1]:/path', false],
            'empty IPv6 port before query' => ['https://[::1]:?q=1', false],
            'empty IPv6 port before fragment' => ['https://[::1]:#portfolio', false],
            'empty IPv6 port at end' => ['https://[::1]:', false],
            'empty port with mixed-case scheme' => ['HtTpS://example.com:/path', false],
            'HTTP without port' => ['http://example.com/', true],
            'HTTPS without port' => ['https://example.com/', true],
            'HTTPS numeric port' => ['https://example.com:443/', true],
            'HTTP numeric port' => ['http://example.com:8080/path', true],
            'IPv6 without port' => ['https://[::1]/path', true],
            'IPv6 numeric port' => ['https://[::1]:8080/path', true],
            'colon in path' => ['https://example.com/path:part', true],
            'colon in query' => ['https://example.com/?q=one:two', true],
            'colon in fragment' => ['https://example.com/#part:two', true],
            'mixed-case scheme and non-authority colons' => ['hTtPs://example.com/path:part?q=one:two#part:two', true],
            'optional empty webpage' => ['', true]
        ];
    }

    /**
     * @dataProvider personalWebpagePortProvider
     */
    public function testPersonalWebpagePortValidation(string $webpage, bool $valid): void {
        $core = $this->createMock(Core::class);
        $user = new User($core);
        $user->setId('url-student');
        $original = [
            'linkedin_username' => 'original-name', 'github_username' => 'original-code',
            'personal_webpage' => 'https://example.com/original'
        ];
        $user->setLinkedinUsername($original['linkedin_username']);
        $user->setGithubUsername($original['github_username']);
        $user->setPersonalWebpage($original['personal_webpage']);
        $core->method('getUser')->willReturn($user);
        $queries = $this->createMock(DatabaseQueries::class);
        $core->method('getQueries')->willReturn($queries);
        $queries->method('hasStudentEnrollment')->with('url-student')->willReturn(true);
        $queries->expects($valid ? $this->once() : $this->never())->method('updateUserContactInfo')->with($this->identicalTo($user));
        $values = ['linkedin_username' => 'new-name', 'github_username' => 'new-code', 'personal_webpage' => $webpage];
        $_POST = array_map(fn($value) => ' ' . $value . ' ', $values);
        $response = (new UserProfileController($core))->changeContactInfo()->json;
        $this->assertSame($valid ? 'success' : 'error', $response['status']);
        if ($valid) {
            $this->assertSame($values, $response['data']);
        }
        else {
            $this->assertSame('Personal webpage must be a valid HTTP or HTTPS URL without login credentials (up to 2048 characters).', $response['message']);
        }
        $expected = $valid ? $values : $original;
        $this->assertSame($expected['linkedin_username'], $user->getLinkedinUsername());
        $this->assertSame($expected['github_username'], $user->getGithubUsername());
        $this->assertSame($expected['personal_webpage'], $user->getPersonalWebpage());
    }

    public static function profileEligibilityProvider(): array {
        return [
            'current student' => [4, User::LEVEL_USER, true],
            'historical student' => [4, User::LEVEL_USER, true],
            'mixed student and instructor' => [1, User::LEVEL_USER, true],
            'faculty with student enrollment' => [1, User::LEVEL_FACULTY, true],
            'instructor only' => [1, User::LEVEL_USER, false],
            'administrator without student enrollment' => [1, User::LEVEL_SUPERUSER, false],
            'faculty without student enrollment' => [1, User::LEVEL_FACULTY, false],
            'default student group without enrollment' => [4, User::LEVEL_USER, false]
        ];
    }

    /**
     * @dataProvider profileEligibilityProvider
     */
    public function testProfileAndPostUseRecordedStudentEligibility(int $group, int $access_level, bool $eligible): void {
        $core = $this->createMock(Core::class);
        $config = $this->createMock(Config::class);
        $locale = $this->createMock(Locale::class);
        $locale->method('getName')->willReturn('en_US');
        $config->method('getLocale')->willReturn($locale);
        $config->method('getDefaultLocaleName')->willReturn('en_US');
        $core->method('getConfig')->willReturn($config);
        $core->method('getSupportedLocales')->willReturn([]);
        $core->method('getCsrfToken')->willReturn('csrf-test');
        $original = [
            'linkedin_username' => 'original-name', 'github_username' => 'original-code',
            'personal_webpage' => 'https://example.com/?a=1&b=2'
        ];
        $user = $this->getMockBuilder(User::class)->setConstructorArgs([$core, $original + [
            'user_id' => 'authenticated-user', 'user_group' => $group, 'user_access_level' => $access_level,
            'user_givenname' => 'Profile', 'user_familyname' => 'Example', 'user_email' => 'profile@example.com', 'time_zone' => 'UTC'
        ]])->onlyMethods(['getDisplayImage'])->getMock();
        $user->method('getDisplayImage')->willReturn(null);
        $core->method('getUser')->willReturn($user);
        $queries = $this->createMock(DatabaseQueries::class);
        $core->method('getQueries')->willReturn($queries);
        $queries->expects($this->exactly(3))->method('hasStudentEnrollment')->with('authenticated-user')->willReturn($eligible);
        $queries->expects($eligible ? $this->exactly(2) : $this->never())->method('updateUserContactInfo')->with($this->identicalTo($user));
        $output = $this->createMock(Output::class);
        $core->method('getOutput')->willReturn($output);
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../../../app/templates'), ['autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('localize', fn($key, $fallback) => $fallback));
        $twig->addFunction(new TwigFunction('base64_image', fn() => ''));
        $output->expects($this->once())->method('renderTwigTemplate')->willReturnCallback(fn($file, $context) => $twig->render($file, $context));
        $controller = new UserProfileController($core);
        $response = $controller->showUserProfile()->web_response;
        $this->assertSame($eligible, $response->parameters[3]);
        $html = (new UserProfileView($core, $output))->showUserProfile(...$response->parameters);
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $this->assertSame($eligible ? 1 : 0, $xpath->query('//*[@id="contact-information"]')->length);
        $this->assertSame($eligible ? 1 : 0, $xpath->query('//*[@id="edit-contact-info-form"]')->length);
        if ($eligible) {
            $buttons = $xpath->query('//*[@id="contact-information"]//button');
            $this->assertSame(1, $buttons->length);
            $button = $buttons->item(0);
            $profile_button = $xpath->query('//*[@id="secondary-email-row"]/button')->item(0);
            $this->assertSame('icon', $button->getAttribute('class'));
            $this->assertSame($profile_button->getAttribute('class'), $button->getAttribute('class'));
            $this->assertSame('edit-contact-info-button', $button->getAttribute('id'));
            $this->assertSame('edit-contact-info-button', $button->getAttribute('data-testid'));
            $this->assertSame('button', $button->getAttribute('type'));
            $this->assertSame('0', $button->getAttribute('tabindex'));
            $this->assertSame('Edit contact information', $button->getAttribute('aria-label'));
            $this->assertSame('Edit contact information', $button->getAttribute('title'));
            $this->assertSame('edit-contact-info-form', $button->getAttribute('aria-controls'));
            $this->assertSame('showUpdateContactInfoForm()', $button->getAttribute('onclick'));
            $this->assertSame('user-info-title', $button->parentNode->getAttribute('class'));
            $icons = $xpath->query('.//i', $button);
            $this->assertSame(1, $icons->length);
            $this->assertSame('fas fa-pencil-alt', $icons->item(0)->getAttribute('class'));
            $this->assertSame($xpath->query('.//i', $profile_button)->item(0)->getAttribute('class'), $icons->item(0)->getAttribute('class'));
            $this->assertSame('true', $icons->item(0)->getAttribute('aria-hidden'));
            $privacy_notice = 'Instructors from all your past, present, and future courses may access this information.';
            $profile_intro = 'Share your professional profiles or portfolio to help instructors stay in touch.';
            $popup_intro = 'You can optionally share your LinkedIn profile, GitHub account, or personal website with your instructors. These links can help instructors stay in touch with you and follow your work after your courses end.';
            $removal_instruction = 'All fields are optional. You can update your information at any time or leave a field empty to remove it.';
            $this->assertSame($profile_intro, trim($xpath->query('//*[@id="contact-information"]/p')->item(0)->textContent));
            $this->assertStringNotContainsString($privacy_notice, $xpath->query('//*[@id="contact-information"]')->item(0)->textContent);
            $popup = $xpath->query('//*[@id="edit-contact-info-form"]')->item(0);
            $paragraphs = $xpath->query('.//div[@class="form-body"]/p', $popup);
            $this->assertSame(3, $paragraphs->length);
            $this->assertSame($popup_intro, trim($paragraphs->item(0)->textContent));
            $privacy_paragraph = $paragraphs->item(1);
            $this->assertSame($privacy_notice, trim($privacy_paragraph->textContent));
            $this->assertSame($privacy_notice, $xpath->query('./strong', $privacy_paragraph)->item(0)->textContent);
            $this->assertSame($removal_instruction, trim($paragraphs->item(2)->textContent));
            $this->assertSame(1, substr_count($html, $privacy_notice));
            foreach ($original as $field => $value) {
                $id = str_replace('_', '-', $field);
                $display = $xpath->query('//*[@id="' . $id . '-row"]/span[contains(@class, "contact-value")]');
                $this->assertSame(1, $display->length);
                $this->assertSame($value, $display->item(0)->textContent);
                $inputs = $xpath->query('.//input[@id="' . $id . '-change"]', $popup);
                $this->assertSame(1, $inputs->length);
                $this->assertSame(1, $xpath->query('following::input[@id="' . $id . '-change"]', $privacy_paragraph)->length);
                $this->assertSame($field, $inputs->item(0)->getAttribute('name'));
                $this->assertSame($value, $inputs->item(0)->getAttribute('value'));
                $labels = $xpath->query('.//label[@for="' . $id . '-change"]', $popup);
                $this->assertSame(1, $labels->length);
                $this->assertSame(trim($xpath->query('//*[@id="' . $id . '-row"]/span[@class="label"]')->item(0)->textContent), trim($labels->item(0)->textContent));
            }
        }

        $posts = [
            ['linkedin_username' => 'new-name', 'github_username' => 'new-code', 'personal_webpage' => 'https://example.com/new'],
            ['linkedin_username' => '', 'github_username' => '', 'personal_webpage' => '']
        ];
        foreach ($posts as $values) {
            $_POST = $values + ['user_id' => 'another-eligible-student'];
            $this->assertSame($eligible ? 'success' : 'error', $controller->changeContactInfo()->json['status']);
            $expected = $eligible ? $values : $original;
            $this->assertSame($expected['linkedin_username'], $user->getLinkedinUsername());
            $this->assertSame($expected['github_username'], $user->getGithubUsername());
            $this->assertSame($expected['personal_webpage'], $user->getPersonalWebpage());
        }
    }
}
