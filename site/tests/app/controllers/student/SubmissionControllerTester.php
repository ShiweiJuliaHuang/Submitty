<?php

namespace tests\app\controllers\student;

use \ZipArchive;
use app\controllers\student\SubmissionController;
use app\exceptions\IOException;
use app\libraries\Core;
use app\libraries\FileUtils;
use app\libraries\GradeableType;
use app\libraries\Utils;
use app\libraries\database\DatabaseQueries;
use app\models\Config;
use app\models\User;
use app\models\gradeable\AutoGradedGradeable;
use app\models\gradeable\AutoGradedVersion;
use app\models\gradeable\AutogradingConfig;
use app\models\gradeable\Gradeable;
use app\models\gradeable\GradedGradeable;
use app\models\gradeable\Submitter;
use app\models\gradeable\TaGradedGradeable;
use tests\BaseUnitTest;
use tests\utils\NullOutput;

/**
 * @runTestsInSeparateProcesses
 */
class SubmissionControllerTester extends BaseUnitTest {
    private static $annotations = [];

    /**
     * @var array
     */
    private $config = [];
    /** @var Core */
    private $core;

    public function setUp(): void {
        // set up variables that logger needs
        $_COOKIE['submitty_token'] = 'test';
        $_SERVER['REMOTE_ADDR'] = 'test';
        $_SERVER['HTTP_USER_AGENT'] = 'test';

        $_REQUEST['vcs_checkout'] = false;
        $_POST['previous_files'] = "";
        $_POST['csrf_token'] = "";
        $_POST['user_id'] = "testUser";
        $_POST['repo_id'] = "";

        $this->config['tmp_path'] = FileUtils::joinPaths(sys_get_temp_dir(), Utils::generateRandomString());
        $this->config['semester'] = "test";
        $this->config['course'] = "test";
        $this->config['course_path'] = FileUtils::joinPaths(
            $this->config['tmp_path'],
            "courses",
            $this->config['semester'],
            $this->config['course']
        );

        $this->assertTrue(FileUtils::createDir($this->config['course_path'], null, true));
        $this->assertTrue(FileUtils::createDir(FileUtils::joinPaths($this->config['course_path'], "submissions")));
        $this->assertTrue(FileUtils::createDir(FileUtils::joinPaths($this->config['tmp_path'], "to_be_graded_queue")));

        $this->core = new Core();
        $this->core->setOutput(new NullOutput($this->core));
        $this->core->setTesting(true);

        $this->core->setUser(new User($this->core, [
            'user_id' => 'testUser',
            'user_firstname' => 'Test',
            'user_lastname' => 'Person',
            'user_email' => '',
            'user_group' => 4
        ]));

        $config = new Config($this->core);
        $config->setSemester($this->config['semester']);
        $config->setCourse($this->config['course']);
        $config->setCoursePath($this->config['course_path']);
        $config->setSubmittyPath($this->config['tmp_path']);
        $this->core->setConfig($config);
        $this->core->getOutput()->loadTwig();

        $highest_version = 0;
        $num_parts = 1;
        $max_size = 1000000; // 1 MB

        if (empty(static::$annotations)) {
            static::$annotations = $this->getAnnotations();
        }
        if (isset(static::$annotations['method']['highestVersion'][0])) {
            $highest_version = intval(static::$annotations['method']['highestVersion'][0]);
        }

        if (isset(static::$annotations['method']['numParts'][0])) {
            $num_parts = intval(static::$annotations['method']['numParts'][0]);
        }

        if (isset(static::$annotations['method']['maxSize'][0])) {
            $max_size = intval(static::$annotations['method']['maxSize'][0]);
        }

        $gradeable = $this->createMockGradeable($num_parts, $max_size);
        $graded_gradeable = $this->createMockGradedGradeable($gradeable, $highest_version);

        $database_queries = $this->createMock(DatabaseQueries::class);

        $database_queries
            ->expects($this->any())
            ->method('getGradeableConfig')
            ->will($this->returnCallback(function ($arg) use ($gradeable) {
                if ($arg === 'test') {
                    return $gradeable;
                }
                else {
                    throw new \InvalidArgumentException();
                }
            }));
        $database_queries->method('getGradedGradeable')->willReturn($graded_gradeable);

        $this->core->setQueries($database_queries);
    }

    /**
     * Helper method to generate a gradeable. We can use annotations in our testcases
     * to set various aspects of the gradeable, namely @numParts, and @maxSize for
     * highest version of submission, number of parts, and filesize respectively.
     *
     * @param int    $num_parts
     * @param double $max_size
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function createMockGradeable($num_parts = 1, $max_size = 1000000., $has_autograding_config = true, $student_view = true) {
        if ($has_autograding_config) {
            $details = [
                'max_submission_size' => $max_size,
                'part_names' => array_fill(0, $num_parts, "")
            ];
            $auto_grading_config = new AutogradingConfig($this->core, $details);
        }

        $submission_open_date = new \DateTime("now", $this->core->getConfig()->getTimezone());
        if ($student_view) {
            $submission_open_date->sub(new \DateInterval('PT1H'));
        }
        else {
            $submission_open_date->add(new \DateInterval('PT1H'));
        }
        $details = [
            'id' => 'test',
            'title' => 'Test Gradeable',
            'instructions_url' => '',
            'ta_instructions' => '',
            'type' => GradeableType::ELECTRONIC_FILE,
            'grader_assignment_method' => 0,
            'min_grading_group' => 3,
            'syllabus_bucket' => 'homework',
            'autograding_config_path' => '/path/to/autograding',
            'vcs' => false,
            'vcs_subdirectory' => '',
            'vcs_host_type' => -1,
            'team_assignment' => false,
            'team_size_max' => 1,
            'ta_grading' => true,
            'scanned_exam' => false,
            'student_view' => $student_view,
            'student_view_after_grades' => false,
            'student_submit' => true,
            'has_due_date' => true,
            'peer_grading' => false,
            'peer_grade_set' => false,
            'late_submission_allowed' => true,
            'precision' => 0.5,
            'regrade_allowed' => true,
            'grade_inquiry_per_component_allowed' => true,
            'discussion_based' => false,
            'discussion_thread_ids' => '',
            'ta_view_start_date' => (new \DateTime("now", $this->core->getConfig()->getTimezone()))->sub(new \DateInterval('PT2H')),
            'submission_open_date' => $submission_open_date,
            'team_lock_date' => new \DateTime("now", $this->core->getConfig()->getTimezone()),
            'submission_due_date' => new \DateTime("9991-01-01 01:01:01", $this->core->getConfig()->getTimezone()),
            'grade_start_date' => new \DateTime("9992-01-01 01:01:01", $this->core->getConfig()->getTimezone()),
            'grade_due_date' => new \DateTime("9993-01-01 01:01:01", $this->core->getConfig()->getTimezone()),
            'grade_released_date' => new \DateTime("9994-01-01 01:01:01", $this->core->getConfig()->getTimezone()),
            'grade_locked_date' => new \DateTime("9995-01-01 01:01:01", $this->core->getConfig()->getTimezone()),
            'late_days' => 2,
            'regrade_request_date' => new \DateTime("9995-01-01 01:01:01", $this->core->getConfig()->getTimezone())
        ];
        $gradeable = new Gradeable($this->core, $details);
        if ($has_autograding_config) {
            $gradeable->setAutogradingConfig($auto_grading_config);
        }
        return $gradeable;
    }

    /**
     * Helper method to generate a graded gradeable.
     *
     * @param int    $highest_version
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function createMockGradedGradeable($gradeable, int $highest_version = 0) {
        $graded_gradeable = new GradedGradeable(
            $this->core,
            $gradeable,
            new Submitter($this->core, $this->core->getUser()),
            []
        );

        $auto_graded_gradeable = new AutoGradedGradeable(
            $this->core,
            $graded_gradeable,
            [
                'active_version' => 0
            ]
        );

        $auto_graded_version = new AutoGradedVersion(
            $this->core,
            $graded_gradeable,
            [
                'version' => $highest_version,
                'non_hidden_non_extra_credit' => 0,
                'non_hidden_extra_credit' => 0,
                'hidden_non_extra_credit' => 0,
                'hidden_extra_credit' => 0,
                'submission_time' => '9999-01-01 01:01:01',
                'autograding_complete' => true
            ]
        );
        $auto_graded_gradeable->setAutoGradedVersions([$auto_graded_version]);
        $graded_gradeable->setAutoGradedGradeable($auto_graded_gradeable);
        return $graded_gradeable;
    }

    /**
     * Cleanup routine for the tester. This deletes any folders/files we created in the tmp directory to hold our fake
     * uploaded files.
     */
    public function tearDown(): void {
        $this->assertTrue(FileUtils::recursiveRmdir($this->config['tmp_path']));
        $_FILES = array();
    }

    /**
     * Creates a file with teh given contents to be used to upload for a specified part.
     *
     * @param string $filename
     * @param string $content
     * @param int    $part
     */
    private function addUploadFile($filename, $content="", $part=1) {
        FileUtils::createDir(FileUtils::joinPaths($this->config['tmp_path'], 'files', 'part'.$part), 0777, true);
        $filepath = FileUtils::joinPaths($this->config['tmp_path'], 'files', 'part'.$part, $filename);
        if (file_put_contents($filepath, $content) === false) {
            throw new IOException("Could not write file to {$filepath}");
        }
        $_FILES["files{$part}"]['name'][] = $filename;
        $_FILES["files{$part}"]['type'][] = FileUtils::getMimeType($filepath);
        $_FILES["files{$part}"]['size'][] = filesize($filepath);
        $_FILES["files{$part}"]['tmp_name'][] = $filepath;
        $_FILES["files{$part}"]['error'][] = null;
    }

    /**
     * Given an array, this will create a zip file using the given name (appending .zip to it) with
     * the $files array describing what will go into the zip, filling out the archive by calling the
     * createZip function recursively.
     *
     * @param string $zip_name
     * @param array  $files
     * @param int    $part
     */
    private function addUploadZip($zip_name, $files, $part=1) {
        $part_path = FileUtils::joinPaths($this->config['tmp_path'], 'files', 'part'.$part);
        $root_path = FileUtils::joinPaths($part_path, $zip_name);
        FileUtils::createDir($root_path, 0777, true);
        $zip_path =  FileUtils::joinPaths($part_path, $zip_name.'.zip');
        $zip = new ZipArchive();
        $zip->open($zip_path, ZipArchive::CREATE || ZipArchive::OVERWRITE);
        $this->createZip($files, $zip, $root_path);
        $zip->close();
        $_FILES["files{$part}"]['name'][] = $zip_name.'.zip';
        $_FILES["files{$part}"]['type'][] = FileUtils::getMimeType($zip_path);
        $_FILES["files{$part}"]['size'][] = filesize($zip_path);
        $_FILES["files{$part}"]['tmp_name'][] = $zip_path;
        $_FILES["files{$part}"]['error'][] = null;
    }

    /**
     * This recursive function fills out a zip archive from the given $files array. For each element in the array,
     * if the value is an array, then the key is a folder name and the value describes the contents of that folder,
     * wherein we create that folder and then recursively call this function for the files in the directory. Otherwise
     * if they key is numeric, then the value is the filename and the file is empty or the key is the filename and the
     * value is its contents. However, if the filename ends with '.zip', then we create a zip archive (containing one
     * empty file) to be put into the zip.
     *
     * @param array       $files
     * @param ZipArchive  $zip
     * @param string      $dir
     * @param string|null $root_dir
     */
    private function createZip($files, $zip, $dir, $root_dir=null) {
        if ($root_dir === null) {
            $root_dir = $dir;
        }
        foreach ($files as $key => $value) {
            if (is_array($value)) {
                $new_dir = FileUtils::joinPaths($dir, $key);
                FileUtils::createDir($new_dir);
                $this->createZip($value, $zip, $new_dir, $root_dir);
            }
            else {
                if (is_numeric($key)) {
                    $filename = $value;
                    $content = "";
                }
                else {
                    $filename = $key;
                    $content = $value;
                }
                $file_path = FileUtils::joinPaths($dir, $filename);
                if (Utils::endsWith($filename, '.zip') === true) {
                    $file = new ZipArchive();
                    $file->open($file_path, ZipArchive::CREATE || ZipArchive::OVERWRITE);
                    $file->addFromString('test1.txt', 'a');
                    $file->close();
                }
                else {
                    file_put_contents($file_path, $content);
                }
                $zip->addFile($file_path, substr($file_path, strlen($root_dir) + 1));
            }
        }
    }

    /**
     * Basic upload, only one part and one file, simple sanity check.
     */
    public function testUploadOneBucket() {
        $this->addUploadFile('test1.txt', 'a');

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertNotEquals($return['status'], 'fail');
        $this->assertEquals($return['status'], 'success');

        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        $this->assertStringEqualsFile(FileUtils::joinPaths($tmp, 'test1.txt'), "a");
        $pattern = '/[0-9]{4}\-[0-1][0-9]\-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]/';
        $this->assertRegExp($pattern, file_get_contents(FileUtils::joinPaths($tmp, ".submit.timestamp")));
        $iter = new \FilesystemIterator($tmp);
        $files = array();
        foreach ($iter as $entry) {
            $this->assertFalse($entry->isDir());
            $this->assertFalse($entry->isLink());
            $this->assertTrue($entry->isFile());
            $files[] = $entry->getFilename();
        }
        sort($files);
        $this->assertEquals(array('.submit.timestamp', 'test1.txt'), $files);
        $touch_file = implode("__", array($this->config['semester'], $this->config['course'], "test", "testUser", "1"));
        $this->assertFileExists(FileUtils::joinPaths($this->config['tmp_path'], "to_be_graded_queue", $touch_file));
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser");
        foreach (new \FilesystemIterator($tmp) as $iter) {
            if ($iter->isDir()) {
                $this->assertEquals("1", $iter->getFilename());
            }
            else {
                $this->assertTrue($iter->isFile());
                $this->assertEquals("user_assignment_settings.json", $iter->getFilename());
                $json = FileUtils::readJsonFile($iter->getPathname());
                $this->assertEquals(1, $json['active_version']);
                $this->assertTrue(isset($json['history']));
                $this->assertEquals(1, count($json['history']));
                $this->assertEquals(1, $json['history'][0]['version']);
                $this->assertRegExp('/[0-9]{4}\-[0-1][0-9]\-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]/', $json['history'][0]['time']);
            }
        }
    }

    /**
     * Test what happens if we have two parts
     *
     * @numParts 2
     */
    public function testUploadTwoBuckets() {
        $this->addUploadFile('test1.txt', 'a');
        $this->addUploadFile('test2.txt', 'b');
        $this->addUploadFile('test2.txt', 'c', 2);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');

        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        $iter = new \RecursiveDirectoryIterator($tmp);
        $files = array();
        while ($iter->getPathname() !== "" && $iter->getFilename() !== "") {
            if ($iter->isDot()) {
                $iter->next();
                continue;
            }
            else if ($iter->isFile()) {
                $this->assertEquals(".submit.timestamp", $iter->getFilename());
            }
            else if ($iter->isDir()) {
                $this->assertTrue(in_array($iter->getFilename(), array('part1', 'part2')));
                $files[$iter->getFilename()] = array();
                $iter2 = $iter->getChildren();
                while ($iter2 !== "" && $iter2->getFilename() !== "") {
                    if ($iter2->isDot()) {
                        $iter2->next();
                        continue;
                    }
                    else if ($iter2->isFile()) {
                        $files[$iter->getFilename()][$iter2->getFilename()] = file_get_contents($iter2->getPathname());
                    }
                    else {
                        $this->fail("Part directory should not contain a directory.");
                    }
                    $iter2->next();
                }
            }
            else {
                $this->fail("Unknown type found in test directory.");
            }
            $iter->next();
        }
        ksort($files);
        $expected = array(
            'part1' => array(
                'test1.txt' => 'a',
                'test2.txt' => 'b',
            ),
            'part2' => array(
                'test2.txt' => 'c'
            )
        );
        $this->assertEquals($expected, $files);
    }

    /**
     * Test what happens if we're uploading a zip that contains a directory.
     */
    public function testZipWithDirectory() {
        $zip = array(
            'testDir' => array(
                'test1.txt' => ''
            ),
            'test2.txt' => ''
        );
        $this->addUploadZip('directory_inside', $zip);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');

        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        $iter = new \RecursiveDirectoryIterator($tmp);
        $filenames = array();
        while ($iter->getPathname() !== "" && $iter->getFilename() !== "") {
            if ($iter->isDot()) {
                $iter->next();
                continue;
            }
            else if ($iter->isFile()) {
                $filenames[] = $iter->getFilename();
            }
            else if ($iter->isDir()) {
                $this->assertEquals("testDir", $iter->getFilename());
                $iter2 = $iter->getChildren();
                while ($iter2 !== "" && $iter2->getFilename() !== "") {
                    if ($iter2->isDot()) {
                        $iter2->next();
                        continue;
                    }
                    else if ($iter2->isFile()) {
                        $this->assertEquals("test1.txt", $iter2->getFilename());
                    }
                    else {
                        $this->fail("Part directory should not contain a directory itself.");
                    }

                    $iter2->next();
                }
            }
            else {
                $this->fail("Unknown type found in test directory.");
            }
            $iter->next();
        }
        sort($filenames);
        $this->assertEquals(array(".submit.timestamp", "test2.txt"), $filenames);
    }

    /**
     * Upload a second version of a gradeable with no previous files and different files per upload. Test
     * that both versions exist and neither bled over to the other.
     */
    public function testSecondVersionNoPrevious() {
        $this->addUploadFile('test1.txt');

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');
        $_FILES = [];

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        $files = array();
        foreach (new \FilesystemIterator($tmp) as $file) {
            $this->assertFalse($file->isDir());
            $files[] = $file->getFilename();
        }
        sort($files);
        $this->assertEquals(array('.submit.timestamp', 'test1.txt'), $files);

        $this->addUploadFile('test2.txt');

        $database_queries = $this->createMock(DatabaseQueries::class);
        $gradeable = $this->createMockGradeable();
        $database_queries->method('getGradeableConfig')->with('test')->willReturn($gradeable);
        $database_queries->method('getGradedGradeable')->willReturn($this->createMockGradedGradeable($gradeable, 1));
        $this->core->setQueries($database_queries);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "2");
        $files = array();
        foreach (new \FilesystemIterator($tmp) as $file) {
            $this->assertFalse($file->isDir());
            $files[] = $file->getFilename();
        }
        sort($files);
        $this->assertEquals(array('.submit.timestamp', 'test2.txt'), $files);

        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser");

        $dirs = array();
        foreach (new \FilesystemIterator($tmp) as $iter) {
            if ($iter->isDir()) {
                $dirs[] = $iter->getFilename();
            }
            else {
                $this->assertTrue($iter->isFile());
                $this->assertEquals("user_assignment_settings.json", $iter->getFilename());
                $json = FileUtils::readJsonFile($iter->getPathname());
                $this->assertEquals(2, $json['active_version']);
                $this->assertTrue(isset($json['history']));
                $this->assertEquals(2, count($json['history']));
                $this->assertEquals(1, $json['history'][0]['version']);
                $this->assertRegExp('/[0-9]{4}\-[0-1][0-9]\-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]/', $json['history'][0]['time']);
                $this->assertEquals(2, $json['history'][1]['version']);
                $this->assertRegExp('/[0-9]{4}\-[0-1][0-9]\-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]/', $json['history'][1]['time']);
            }
        }
        sort($dirs);
        $this->assertEquals(array('1', '2'), $dirs);
    }

    /**
     * @numParts 2
     */
    public function testSecondVersionPreviousTwoParts() {
        $this->addUploadFile("test1.txt", "", 1);
        $this->addUploadFile("test1.txt", "", 2);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');
        $_FILES = [];

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');

        $_POST['previous_files'] = json_encode(array(0 => array('test1.txt'), 1 => array('test1.txt')));

        $database_queries = $this->createMock(DatabaseQueries::class);
        $gradeable = $this->createMockGradeable(2);
        $database_queries->method('getGradeableConfig')->with('test')->willReturn($gradeable);
        $database_queries->method('getGradedGradeable')->willReturn($this->createMockGradedGradeable($gradeable, 1));
        $this->core->setQueries($database_queries);

        $this->addUploadFile("test2.txt", "", 1);
        $this->addUploadFile("test2.txt", "", 2);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');
    }

    /**
     * Upload a second version of a gradeable that includes previous files, but there's no overlap in file names
     * so we should have one file in version 1 and two files in version 2
     */
    public function testSecondVersionPreviousNoOverlap() {
        $this->addUploadFile('test1.txt');

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');
        $_FILES = [];

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        $files = array();
        foreach (new \FilesystemIterator($tmp) as $file) {
            $this->assertFalse($file->isDir());
            $files[] = $file->getFilename();
        }
        sort($files);
        $this->assertEquals(array('.submit.timestamp', 'test1.txt'), $files);

        $this->addUploadFile('test2.txt');
        $_POST['previous_files'] = json_encode(array(array('test1.txt')));


        $database_queries = $this->createMock(DatabaseQueries::class);
        $gradeable = $this->createMockGradeable();
        $database_queries->method('getGradeableConfig')->with('test')->willReturn($gradeable);
        $database_queries->method('getGradedGradeable')->willReturn($this->createMockGradedGradeable($gradeable, 1));
        $this->core->setQueries($database_queries);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "2");
        $files = array();
        foreach (new \FilesystemIterator($tmp) as $file) {
            $this->assertFalse($file->isDir());
            $files[] = $file->getFilename();
        }
        sort($files);
        $this->assertEquals(array('.submit.timestamp', 'test1.txt', 'test2.txt'), $files);
    }

    /**
     * Upload a second version that has previous files that has the same filename as the file that's being uploaded.
     * This should only include the version that was uploaded (and not use the previous).
     */
    public function testSecondVersionPreviousOverlap() {
        $this->addUploadFile('test1.txt', 'old_file');

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');
        $_FILES = [];

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        $files = array();
        foreach (new \FilesystemIterator($tmp) as $file) {
            $this->assertFalse($file->isDir());
            $files[] = $file->getFilename();
            if ($file->getFilename() === "test1.txt") {
                $this->assertStringEqualsFile($file->getPathname(), "old_file");
            }
        }
        sort($files);
        $this->assertEquals(array('.submit.timestamp', 'test1.txt'), $files);

        $this->addUploadFile('test1.txt', 'new_file');
        $_POST['previous_files'] = json_encode(array(array('test1.txt')));

        $database_queries = $this->createMock(DatabaseQueries::class);
        $gradeable = $this->createMockGradeable();
        $database_queries->method('getGradeableConfig')->with('test')->willReturn($gradeable);
        $database_queries->method('getGradedGradeable')->willReturn($this->createMockGradedGradeable($gradeable, 1));
        $this->core->setQueries($database_queries);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "2");
        $files = array();
        foreach (new \FilesystemIterator($tmp) as $file) {
            $this->assertFalse($file->isDir());
            $files[] = $file->getFilename();
            if ($file->getFilename() === "test1.txt") {
                $this->assertStringEqualsFile($file->getPathname(), "new_file");
            }
        }
        sort($files);
        $this->assertEquals(array('.submit.timestamp', 'test1.txt'), $files);
    }

    /**
     * This tests what happens when we upload a second version of the gradeable that is a zip that contains a file
     * that overlaps the file from the first version.
     */
    public function testSecondVersionPreviousOverlapZip() {
        $this->addUploadFile('test1.txt', 'old_file');

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');
        $_FILES = [];

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        $files = array();
        foreach (new \FilesystemIterator($tmp) as $file) {
            $this->assertFalse($file->isDir());
            $files[] = $file->getFilename();
            if ($file->getFilename() === "test1.txt") {
                $this->assertStringEqualsFile($file->getPathname(), "old_file");
            }
        }
        sort($files);
        $this->assertEquals(array('.submit.timestamp', 'test1.txt'), $files);

        $this->addUploadZip('overlap', array('test1.txt' => 'new_file'));
        $_POST['previous_files'] = json_encode(array(array('test1.txt')));

        $database_queries = $this->createMock(DatabaseQueries::class);
        $gradeable = $this->createMockGradeable();
        $database_queries->method('getGradeableConfig')->with('test')->willReturn($gradeable);
        $database_queries->method('getGradedGradeable')->willReturn($this->createMockGradedGradeable($gradeable, 1));
        $this->core->setQueries($database_queries);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "2");
        $files = array();
        foreach (new \FilesystemIterator($tmp) as $file) {
            $this->assertFalse($file->isDir());
            $files[] = $file->getFilename();
            if ($file->getFilename() === "test1.txt") {
                $this->assertStringEqualsFile($file->getPathname(), "new_file");
            }
        }
        sort($files);
        $this->assertEquals(array('.submit.timestamp', 'test1.txt'), $files);
    }

    /**
     * Test uploading a zip that contains a file and a zip. We only unzip one level so we the inner zip should
     * be left alone.
     */
    public function testZipInsideZip() {
        $zip = array(
            'test1.txt' => 'a',
            'basic_zip.zip'
        );
        $this->addUploadZip('zip_inside', $zip);

        $controller = new SubmissionController($this->core);
        $return =  $controller->ajaxUploadSubmission('test');

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');

        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        $iter = new \RecursiveDirectoryIterator($tmp);
        $files = array();
        while ($iter->getPathname() !== "" && $iter->getFilename() !== "") {
            if ($iter->isDot()) {
                $iter->next();
                continue;
            }
            else if ($iter->isFile()) {
                $files[] = $iter->getFilename();
            }
            else {
                $this->fail("Unknown type found in test directory.");
            }
            $iter->next();
        }
        sort($files);
        $this->assertEquals(array(".submit.timestamp", "basic_zip.zip", "test1.txt"), $files);
    }

    /**
     * This tests what happens if we upload a zip that contains "test.txt" and "test2.txt" and try
     * uploading "test.txt" to the server, in that order. The free "test.txt" file should overwrite the one
     * in the zip (the one not in a zip contains a single 'a' while the two files in the zip are blank).
     */
    public function testSameFilenameInZip() {
        $this->addUploadZip('zippedfiles', array('test.txt' => 'zip_file', 'test2.txt' => 'zip_file2'));
        $this->addUploadFile('test.txt', 'non_zip_file');

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');

        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        $this->assertStringEqualsFile(FileUtils::joinPaths($tmp, "test.txt"), "non_zip_file");
        $this->assertStringEqualsFile(FileUtils::joinPaths($tmp, "test2.txt"), "zip_file2");
    }

    /**
     * This tests the same thing as testSameFilenameInZip(), however we submit "test.txt" before "zippedfiles.zip"
     */
    public function testSameFilenameInZipReversed() {
        $this->addUploadFile('test.txt', 'non_zip_file');
        $this->addUploadZip('zippedfiles', array('test.txt' => 'zip_file', 'test2.txt' => 'zip_file2'));

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');

        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        $this->assertStringEqualsFile(FileUtils::joinPaths($tmp, "test.txt"), "zip_file");
        $this->assertStringEqualsFile(FileUtils::joinPaths($tmp, "test2.txt"), "zip_file2");
        $files = array();
        foreach (new \FilesystemIterator($tmp) as $iter) {
            $this->assertTrue($iter->isFile());
            $files[] = $iter->getFilename();
        }
        sort($files);
        $this->assertEquals(array('.submit.timestamp', 'test.txt', 'test2.txt'), $files);

    }

    public function testFilenameWithSpaces() {
        $this->addUploadFile("filename with spaces.txt");

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');

        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        $files = array();
        foreach (new \FilesystemIterator($tmp) as $iter) {
            $this->assertTrue($iter->isFile());
            $files[] = $iter->getFilename();
        }
        sort($files);
        $this->assertEquals(array('.submit.timestamp', 'filename with spaces.txt'), $files);
    }

    public function testZipContaingFilesWithSpaces() {
        $zip = array(
            'folder with spaces' => array('filename with spaces2.txt'),
            'filename with spaces.txt'
        );
        $this->addUploadZip('contains_spaces', $zip);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        $files = array();
        $iter = new \RecursiveDirectoryIterator($tmp);
        while ($iter->getPathname() !== "" && $iter->getFilename() !== "") {
            if ($iter->isDot()) {
                $iter->next();
                continue;
            }
            else if ($iter->isDir()) {
                $this->assertEquals("folder with spaces", $iter->getFilename());
                foreach (new \FilesystemIterator($iter->getPathname()) as $iter2) {
                    $this->assertTrue($iter2->isFile());
                    $this->assertEquals("filename with spaces2.txt", $iter2->getFilename());
                }
            }
            else if ($iter->isFile()) {
                $files[] = $iter->getFilename();
            }
            else {
                $this->fail("Invalid type found in upload");
            }
            $iter->next();
        }

        sort($files);
        $this->assertEquals(array('.submit.timestamp', 'filename with spaces.txt'), $files);
    }

    public function testVcsUpload() {
        $_POST['git_repo_id'] = "some_repo_id";
        $_POST['vcs_checkout'] = "true";

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');

        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        $files = array();
        foreach (new \FilesystemIterator($tmp) as $iter) {
            $this->assertTrue($iter->isFile());
            $files[] = $iter->getFilename();
        }
        sort($files);
        $this->assertEquals(array('.submit.VCS_CHECKOUT', '.submit.timestamp'), $files);
        $touch_file = implode("__", array($this->config['semester'], $this->config['course'], "test", "testUser", "1"));
        $this->assertFileExists(FileUtils::joinPaths($this->config['tmp_path'], "to_be_graded_queue", "VCS__".$touch_file));
    }

    public function testEmptyPost() {
        $_POST = array();

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertRegExp("/Empty POST request. This may mean that the sum size of your files are greater than [0-9]*M./", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    /**
     * Test that error is thrown when trying to upload to a gradeable id that does not exist in
     * our gradeable list
     */
    public function testErrorInvalidGradeableId() {
        $this->core->getQueries()->method('getGradeableConfig')->with('fake')->will($this->throwException(new \InvalidArgumentException()));

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('fake');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Invalid gradeable id 'fake'", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    /**
     * Test that error is thrown when system is trying to make a folder for the assignment (in the submissions
     * folder) and it cannot.
     */
    public function testFailureToCreateGradeableFolder() {
        $this->core->getConfig()->setCoursePath('/invalid/folder/that/does/not/exist');
        $this->core->getConfig()->setSubmittyPath('/invalid/folder/that/does/not/exist');

        $database_queries = $this->createMock(DatabaseQueries::class);
        $gradeable = $this->createMockGradeable();
        $database_queries->method('getGradeableConfig')->with('test')->willReturn($gradeable);
        $database_queries->method('getGradedGradeable')->willReturn($this->createMockGradedGradeable($gradeable));
        $this->core->setQueries($database_queries);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Failed to make folder for this assignment.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    public function testFailureToCreateStudentFolder() {
        FileUtils::createDir(FileUtils::joinPaths($this->config['course_path'], "submissions", "test"), 0444);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Failed to make folder for this assignment for the user.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
        FileUtils::recursiveChmod($this->config['course_path'], 0777);
    }

    public function testFailureToCreateVersionFolder() {
        FileUtils::createDir(FileUtils::joinPaths($this->config['course_path'], "submissions", "test"));
        FileUtils::createDir(FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser"), 0444);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Failed to make folder for the current version.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
        FileUtils::recursiveChmod($this->config['course_path'], 0777);
    }

    /**
     * @numParts 2
     */
    public function testFailureToCreatePartFolder() {
        FileUtils::createDir(FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser"), null, true);
        FileUtils::createDir(FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1"), 0444);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Failed to make the folder for part 1.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
        FileUtils::recursiveChmod($this->config['course_path'], 0777);
    }

    public function testFileUploadError() {
        $_FILES["files1"]['name'][] = "test.txt";
        $_FILES["files1"]['type'][] = "";
        $_FILES["files1"]['size'][] = 0;
        $_FILES["files1"]['tmp_name'][] = "";
        $_FILES["files1"]['error'][] = UPLOAD_ERR_PARTIAL;

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Upload Failed: test.txt failed to upload. Error message: The file was only partially uploaded.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    public function testNoFilesToSubmit() {
        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("No files to be submitted.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    public function testErrorPreviousFilesFirstVersion() {
        $_POST['previous_files'] = json_encode(array(0=>array('test.txt')));

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("No submission found. There should not be any files from a previous submission.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    /**
     * @highestVersion 2
     */
    public function testErrorMissingPreviousFolder() {
        $_POST['previous_files'] = json_encode(array(0 => array('test.txt')));

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Files from previous submission not found. Folder for previous submission does not exist.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    public function testErrorMissingPreviousFile() {
        $this->addUploadFile('test1.txt');

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');
        $_FILES = [];

        $this->assertTrue($return['status'] == 'success');

        $_POST['previous_files'] = json_encode(array(0 => array('missing.txt')));
        $this->addUploadFile('test1.txt');

        $database_queries = $this->createMock(DatabaseQueries::class);
        $gradeable = $this->createMockGradeable();
        $database_queries->method('getGradeableConfig')->with('test')->willReturn($gradeable);
        $database_queries->method('getGradedGradeable')->willReturn($this->createMockGradedGradeable($gradeable, 1));
        $this->core->setQueries($database_queries);


        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("File 'missing.txt' does not exist in previous submission.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    /**
     * We are not running through all possible invalid filenames (as the list might grow) as that is tested elsewhere,
     * just that we're using that function at all really.
     */
    public function testInvalidFilename() {
        $this->addUploadFile('in"valid.txt');

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Error: You may not use quotes, backslashes or angle brackets in your file name in\"valid.txt.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    public function testInvalidFilenameInZip() {
        $this->addUploadZip("invalid", array('in"valid.txt'));

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Error: You may not use quotes, backslashes or angle brackets in your filename for files inside invalid.zip.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    /**
     * @maxSize 0
     */
    public function testErrorFileTooBig() {
        $this->addUploadFile('test1.txt', 'a');

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("File(s) uploaded too large.  Maximum size is 0 kb. Uploaded file(s) was 0.001 kb.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    /**
     * Because our system does not recursively expand zip files with reckless disregard, we only need to worry
     * about someone hiding a big sized file in the outermost zip.
     */
    public function testErrorFilesInZipTooBig() {
        $this->addUploadZip('zip_bomb', array('bomb.txt' => str_repeat('01', 5120000)));

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail', "An error should have happened");
        $this->assertEquals("File(s) uploaded too large.  Maximum size is 1000 kb. Uploaded file(s) was 10240 kb.",
            $return['message']);
        $this->assertFalse($return['status'] == 'success');
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        $this->assertFalse(is_dir($tmp));
    }

    public function testErrorOnBrokenZip() {
        $this->addUploadZip('broken', array('test1.txt'));
        $path = FileUtils::joinPaths($this->config['tmp_path'], 'files', 'part1', 'broken.zip');
        $fh = fopen($path, 'r+') or die("can't open file");
        $stat = fstat($fh);
        ftruncate($fh, $stat['size']-1);
        fclose($fh);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Could not properly unpack zip file. Error message: Invalid or uninitialized Zip object.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    public function testErrorOnCopyingPrevious() {
        $this->addUploadFile('test1.txt');

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');
        $_FILES = [];

        $this->assertFalse($return['status'] == 'fail');
        $this->assertTrue($return['status'] == 'success');
        $prev = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1", "test1.txt");

        $_POST['previous_files'] = json_encode(array(0 => array('test1.txt')));
        chmod($prev, 0000);

        $database_queries = $this->createMock(DatabaseQueries::class);
        $gradeable = $this->createMockGradeable();
        $database_queries->method('getGradeableConfig')->with('test')->willReturn($gradeable);
        $database_queries->method('getGradedGradeable')->willReturn($this->createMockGradedGradeable($gradeable, 1));
        $this->core->setQueries($database_queries);


        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Failed to copy previously submitted file test1.txt to current submission.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
        chmod($prev, 0777);
    }

    public function testErrorOnCopyingFile() {
        $this->addUploadFile('test1.txt');
        FileUtils::createDir(FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser"), null, true);
        FileUtils::createDir(FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1"), 0444);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Failed to copy uploaded file test1.txt to current submission.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    public function testErrorCleanupTempFiles() {
        $dst_dir = FileUtils::joinPaths($this->config['tmp_path'], "files");
        $dst_file = FileUtils::joinPaths($dst_dir, "test.txt");
        FileUtils::createDir($dst_dir);
        file_put_contents($dst_file, "a");
        $_FILES["files1"]['name'][] = "test1.txt";
        $_FILES["files1"]['type'][] = FileUtils::getMimeType($dst_file);
        $_FILES["files1"]['size'][] = filesize($dst_file);
        $_FILES["files1"]['tmp_name'][] = $dst_file;
        $_FILES["files1"]['error'][] = null;
        chmod($dst_dir, 0550);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Failed to delete the uploaded file test1.txt from temporary storage.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
        chmod($dst_dir, 0777);
    }

    /**
     * Test that we cannot just set the $_FILES array manually under normal operation (not within our
     * test framework) and that we'll get an error.
     */
    public function testErrorFakeFiles() {
        $this->addUploadFile('test1.txt');
        $this->core->setTesting(false);

        $database_queries = $this->createMock(DatabaseQueries::class);
        $gradeable = $this->createMockGradeable();
        $database_queries->method('getGradeableConfig')->with('test')->willReturn($gradeable);
        $database_queries->method('getGradedGradeable')->willReturn($this->createMockGradedGradeable($gradeable));
        $this->core->setQueries($database_queries);


        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("The tmp file 'test1.txt' was not properly uploaded.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    public function testErrorCreateVcsFile() {
        $_POST['git_repo_id'] = "some_repo_id";
        $_POST['vcs_checkout'] = "true";
        FileUtils::createDir(FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser"), null, true);
        FileUtils::createDir(FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1"), 0444);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Failed to touch file for vcs submission.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    public function testErrorCreateQueueFile() {
        $this->addUploadFile('test1.txt');
        $dir = FileUtils::joinPaths($this->config['tmp_path'], "to_be_graded_queue");
        $this->assertTrue(FileUtils::recursiveRmdir($dir));
        $this->assertTrue(FileUtils::createDir($dir, 0444));

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Failed to create file for grading queue.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    public function testErrorBrokenHistoryFile() {
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser");
        FileUtils::createDir($tmp, null, true);
        file_put_contents(FileUtils::joinPaths($tmp, "user_assignment_settings.json"), "]invalid_json[");
        $this->addUploadFile('test1.txt');

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Failed to open settings file.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    /**
     * We're testing that rolling back the history works on failure to upload the second version of the file
     */
    public function testErrorHistorySecondVersion() {
        $this->addUploadFile('test1.txt');

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');
        $_FILES = [];

        $this->assertTrue($return['status'] == 'success');

        $dir = FileUtils::joinPaths($this->config['tmp_path'], "to_be_graded_queue");
        $this->assertTrue(FileUtils::recursiveRmdir($dir));
        $this->assertTrue(FileUtils::createDir($dir, 0444));

        $this->addUploadFile('test1.txt');

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Failed to create file for grading queue.", $return['message']);
        $this->assertFalse($return['status'] == 'success');

        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser");
        foreach (new \FilesystemIterator($tmp) as $iter) {
            if ($iter->isDir()) {
                $this->assertEquals("1", $iter->getFilename());
            }
            else {
                $this->assertTrue($iter->isFile());
                $this->assertEquals("user_assignment_settings.json", $iter->getFilename());
                $json = FileUtils::readJsonFile($iter->getPathname());
                $this->assertEquals(1, $json['active_version']);
                $this->assertTrue(isset($json['history']));
                $this->assertEquals(1, count($json['history']));
                $this->assertEquals(1, $json['history'][0]['version']);
                $this->assertRegExp('/[0-9]{4}\-[0-1][0-9]\-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]/', $json['history'][0]['time']);
            }
        }
    }

    public function testErrorWriteSettingsFile() {
        $this->addUploadFile('test1.txt');
        $dir = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser");
        FileUtils::createDir($dir, null, true);
        $settings = FileUtils::joinPaths($dir, "user_assignment_settings.json");
        file_put_contents($settings, '{"active_version": 0, "history": []}');
        chmod($settings, 0444);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Failed to write to settings file.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
        chmod($settings, 0777);
    }

    public function testErrorWriteTimestampFile() {
        $this->addUploadFile('test1.txt');
        $dir = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser", "1");
        FileUtils::createDir($dir, null, true);
        $timestamp = FileUtils::joinPaths($dir, ".submit.timestamp");
        file_put_contents($timestamp, "Failed to save timestamp file for this submission.");
        chmod($timestamp, 0444);

        $controller = new SubmissionController($this->core);
        $return = $controller->ajaxUploadSubmission('test');

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Failed to save timestamp file for this submission.", $return['message']);
        $this->assertFalse($return['status'] == 'success');
    }

    public function testShowHomeworkPageNoGradeable() {
        $controller = new SubmissionController($this->core);
        $return = $controller->showHomeworkPage('invalid');

        $this->assertTrue($return['error']);
        $this->assertEquals("No gradeable with that id.", $return['message']);
    }

    public function testShowHomeworkValid() {
        $now = new \DateTime("now", $this->core->getConfig()->getTimezone());

        $gradeable = $this->createMockGradeable();
        $graded_gradeable = $this->createMockGradedGradeable($gradeable);

        $ta_graded_gradeable = $this->createMockModel(TaGradedGradeable::class);
        $graded_gradeable->setTaGradedGradeable($ta_graded_gradeable);

        $database_queries = $this->createMock(DatabaseQueries::class);
        $database_queries->method('getGradeableConfig')->with('test')->willReturn($gradeable);
        $database_queries->method('getGradedGradeable')->willReturn($graded_gradeable);
        $this->core->setQueries($database_queries);

        $controller = new SubmissionController($this->core);
        $return = $controller->showHomeworkPage('test');

        $this->assertFalse($return['error']);
        $this->assertEquals("test", $return['id']);
    }

    public function testShowHomeworkNoConfig() {
        $gradeable = $this->createMockGradeable(1, 1000, false);

        $database_queries = $this->createMock(DatabaseQueries::class);
        $database_queries->method('getGradeableConfig')->with('test')->willReturn($gradeable);
        $this->core->setQueries($database_queries);

        $controller = new SubmissionController($this->core);
        $return = $controller->showHomeworkPage('test');

        $this->assertEquals("test", $return['id']);
        $this->assertTrue($return['error']);
    }

    public function testShowHomeworkNoAccess() {
        $core = $this->createMockCore(array(), array('access_grading' => false));
        $gradeable = $this->createMockGradeable(1, 1000, true, false);
        $core->getQueries()->method('getGradeableConfig')->with('test')->willReturn($gradeable);

        $controller = new SubmissionController($core);
        $return = $controller->showHomeworkPage('test');

        $this->assertTrue($return['error']);
        $this->assertEquals("No gradeable with that id.", $return['message']);
    }

    public function testUpdateNegativeVersion() {
        $controller = new SubmissionController($this->core);
        $return = $controller->updateSubmissionVersion('test', -1);

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Cannot set the version below 0.", $return['message']);
    }

    /**
     * @highestVersion 1
     */
    public function testUpdateInvalidVersion() {
        $controller = new SubmissionController($this->core);
        $return = $controller->updateSubmissionVersion('test', 2);

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Cannot set the version past 1.", $return['message']);
    }

    /**
     * @highestVersion 2
     */
    public function testUpdateNoInvalidSettingsFile() {
        $controller = new SubmissionController($this->core);
        $return = $controller->updateSubmissionVersion('test', 1);

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Failed to open settings file.", $return['message']);
    }

    /**
     * @highestVersion 2
     */
    public function testUpdateCannotWriteSettingsFile() {
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser");
        FileUtils::createDir($tmp, null, true);
        $json = json_encode(array('active_version' => 1, 'history' => array('version' => 0, 'time' => '')));
        $settings = FileUtils::joinPaths($tmp, "user_assignment_settings.json");
        file_put_contents($settings, $json);
        chmod($settings, 0444);

        $controller = new SubmissionController($this->core);
        $return = $controller->updateSubmissionVersion('test', 1);

        $this->assertTrue($return['status'] == 'fail');
        $this->assertEquals("Could not write to settings file.", $return['message']);
    }

    public function testUpdateCancelSubmission() {
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser");
        FileUtils::createDir($tmp, null, true);
        $json = json_encode(array('active_version' => 1, 'history' => array(array('version' => 0, 'time' => ''))));
        $settings = FileUtils::joinPaths($tmp, "user_assignment_settings.json");
        file_put_contents($settings, $json);

        $controller = new SubmissionController($this->core);
        $return = $controller->updateSubmissionVersion('test', 0);

        $this->assertFalse($return['status'] == 'fail');
        $this->assertEquals("Cancelled submission for gradeable.", $return['data']['message']);
        $this->assertEquals(0, $return['data']['version']);
        $json = json_decode(file_get_contents($settings), true);
        $this->assertEquals(0, $json['active_version']);
        $this->assertTrue(isset($json['history']));
        $this->assertEquals(2, count($json['history']));
        $this->assertEquals(0, $json['history'][1]['version']);
        $this->assertRegExp('/[0-9]{4}\-[0-1][0-9]\-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]/', $json['history'][1]['time']);
    }

    /**
     * @highestVersion 5
     */
    public function testUpdateSubmission() {
        $tmp = FileUtils::joinPaths($this->config['course_path'], "submissions", "test", "testUser");
        FileUtils::createDir($tmp, null, true);
        $json = json_encode(array('active_version' => 1, 'history' => array(array('version' => 0, 'time' => ''))));
        $settings = FileUtils::joinPaths($tmp, "user_assignment_settings.json");
        file_put_contents($settings, $json);

        $controller = new SubmissionController($this->core);
        $return = $controller->updateSubmissionVersion('test', 4);

        $this->assertFalse($return['status'] == 'fail');
        $this->assertEquals("Updated version of gradeable to version #4.", $return['data']['message']);
        $this->assertEquals(4, $return['data']['version']);
        $json = json_decode(file_get_contents($settings), true);
        $this->assertEquals(4, $json['active_version']);
        $this->assertTrue(isset($json['history']));
        $this->assertEquals(2, count($json['history']));
        $this->assertEquals(4, $json['history'][1]['version']);
        $this->assertRegExp('/[0-9]{4}\-[0-1][0-9]\-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]/', $json['history'][1]['time']);
    }

    /*
     * Test should fail with no results.json
     */
    public function testCheckRefreshFailed1() {
        $controller = new SubmissionController($this->core);
        $return = $controller->checkRefresh('test', 1);

        $this->assertFalse($return['refresh']);
        $this->assertEquals("NO_REFRESH", $return['string']);
    }

    /*
     * Test should fail with no database data
     */
    public function testCheckRefreshFailed2() {
        $tmp = FileUtils::joinPaths($this->config['course_path'], "results", "test", "testUser", "1");
        FileUtils::createDir($tmp, null, true);
        touch(FileUtils::joinPaths($tmp, "results.json"));

        $controller = new SubmissionController($this->core);
        $return = $controller->checkRefresh('test', 1);

        $this->assertFalse($return['refresh']);
        $this->assertEquals("NO_REFRESH", $return['string']);
    }


    /*
     * Test should pass with database data and results.json
     */
    public function testCheckRefreshSuccess() {
        $tmp = FileUtils::joinPaths($this->config['course_path'], "results", "test", "testUser", "1");
        FileUtils::createDir($tmp, null, true);
        touch(FileUtils::joinPaths($tmp, "results.json"));
        $this->core->getQueries()->method('getGradeableVersionHasAutogradingResults')->willReturn(true);

        $controller = new SubmissionController($this->core);
        $return = $controller->checkRefresh('test', 1);

        $this->assertTrue($return['refresh']);
        $this->assertEquals("REFRESH_ME", $return['string']);
    }

}
