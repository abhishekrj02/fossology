<?php
/*
 SPDX-FileCopyrightText: © 2020 Siemens AG
 Author: Gaurav Mishra <mishra.gaurav@siemens.com>

 SPDX-License-Identifier: GPL-2.0-only
*/
/**
 * @file
 * @brief Controller for folder queries
 */

namespace Fossology\UI\Api\Controllers
{

  /**
   * Override to common-folder function GetFolderArray
   */
  function GetFolderArray($a, &$b)
  {
    if ($a == 2) {
      $b[2] = "root";
      $b[3] = "root-child1";
      $b[4] = "root-child2";
    } else {
      $b[$a] = "singlefolder";
    }
  }

  /**
   * Override to common-folder function FolderGetName
   */
  function FolderGetName($folderId)
  {
    return "$folderId-folder";
  }

  /**
   * Override to common-folder function Folder2Path
   */
  function Folder2Path($folderId)
  {
    $folderList = array();
    $folderList[] = ["folder_pk" => 2, "folder_name" => FolderGetName(2)];
    $folderList[] = ["folder_pk" => 3, "folder_name" => FolderGetName(3)];
    return $folderList;
  }
}

namespace Fossology\UI\Api\Test\Controllers
{

  use Fossology\Lib\Dao\FolderDao;
  use Fossology\Lib\Data\Folder\Folder;
  use Fossology\UI\Api\Controllers\FolderController;
  use Fossology\UI\Api\Exceptions\HttpBadRequestException;
  use Fossology\UI\Api\Exceptions\HttpForbiddenException;
  use Fossology\UI\Api\Exceptions\HttpNotFoundException;
  use Fossology\UI\Api\Helper\DbHelper;
  use Fossology\UI\Api\Helper\ResponseHelper;
  use Fossology\UI\Api\Helper\RestHelper;
  use Fossology\UI\Api\Models\ApiVersion;
  use Fossology\UI\Api\Models\Info;
  use Fossology\UI\Api\Models\InfoType;
  use Mockery as M;
  use Psr\Container\ContainerInterface;
  use Slim\Psr7\Factory\StreamFactory;
  use Slim\Psr7\Headers;
  use Slim\Psr7\Request;
  use Slim\Psr7\Uri;

  /**
   * @class FolderControllerTest
   * @brief Test for FolderController
   */
  class FolderControllerTest extends \PHPUnit\Framework\TestCase
  {

    /**
     * @var DbHelper $dbHelper
     * DbHelper mock
     */
    private $dbHelper;
    /**
     * @var FolderDao $folderDao
     * FolderDao mock
     */
    private $folderDao;
    /**
     * @var RestHelper $restHelper
     * RestHelper mock
     */
    private $restHelper;
    /**
     * @var FolderController $folderController
     * FolderController object to test
     */
    private $folderController;
    /**
     * @var integer $userId
     * Current user ID to test
     */
    private $userId;
    /**
     * @var folder_create $folderPlugin
     * Folder plugin object to mock
     */
    private $folderPlugin;
    /**
     * @var admin_folder_delete $deletePlugin
     * Delagent folder delete object to mock
     */
    private $deletePlugin;
    /**
     * @var folder_properties $folderPropertiesPlugin
     * Folder properties plugin to mock
     */
    private $folderPropertiesPlugin;
    /**
     * @var AdminContentMove $folderContentPlugin
     * Folder copy/move plugin object to mock
     */
    private $folderContentPlugin;

    /**
     * @var integer $assertCountBefore
     * Assertions before running tests
     */
    private $assertCountBefore;

    /**
     *
     * @var StreamFactory $streamFactory
     * Stream factory to create body streams.
     */
    private $streamFactory;
    /**
     * @var M\MockInterface $adminPlugin
     */
    private $folderContents;
    private $container;

    /**
     * @brief Setup test objects
     * @see PHPUnit_Framework_TestCase::setUp()
     */
    protected function setUp() : void
    {
      global $container;
      $this->userId = 2;
      $container = M::mock('ContainerBuilder');
      $this->dbHelper = M::mock(DbHelper::class);
      $this->restHelper = M::mock(RestHelper::class);
      $this->folderDao = M::mock(FolderDao::class);
      $this->folderPlugin = M::mock('folder_create');
      $this->deletePlugin = M::mock('admin_folder_delete');
      $this->folderPropertiesPlugin = M::mock('folder_properties');
      $this->folderContentPlugin = M::mock('content_move');
      $this->folderContents = M::mock('foldercontents');
      $this->container = M::mock(ContainerInterface::class);

      $this->restHelper->shouldReceive('getDbHelper')->andReturn($this->dbHelper);
      $this->restHelper->shouldReceive('getFolderDao')->andReturn($this->folderDao);
      $this->restHelper->shouldReceive('getUserId')->andReturn($this->userId);
      $this->restHelper->shouldReceive('getPlugin')
        ->withArgs(array('folder_create'))->andReturn($this->folderPlugin);
      $this->restHelper->shouldReceive('getPlugin')
        ->withArgs(array('admin_folder_delete'))->andReturn($this->deletePlugin);
      $this->restHelper->shouldReceive('getPlugin')
        ->withArgs(array('folder_properties'))
        ->andReturn($this->folderPropertiesPlugin);
      $this->restHelper->shouldReceive("getPlugin")
        ->withArgs(array("foldercontents"))
        ->andReturn($this->folderContentPlugin);
      $this->restHelper->shouldReceive('getPlugin')
        ->withArgs(array('content_move'))
        ->andReturn($this->folderContentPlugin);

      $container->shouldReceive('get')->withArgs(array(
        'helper.restHelper'))->andReturn($this->restHelper);
      $this->folderController = new FolderController($container);
      $this->assertCountBefore = \Hamcrest\MatcherAssert::getCount();
      $this->streamFactory = new StreamFactory();
    }

    /**
     * @brief Remove test objects
     * @see PHPUnit_Framework_TestCase::tearDown()
     */
    protected function tearDown() : void
    {
      $this->addToAssertionCount(
        \Hamcrest\MatcherAssert::getCount() - $this->assertCountBefore);
      M::close();
    }

    /**
     * Helper function to generate pseudo Folder objects
     *
     * @param integer $id Folder ID to generate
     * @return NULL|\Fossology\Lib\Data\Folder\Folder
     */
    public function getFolder($id)
    {
      $name = "";
      switch ($id) {
        case 2: $name = "root";break;
        case 3: $name = "root-child1";break;
        case 4: $name = "root-child2";break;
        case -1: return null;
        default: $name = "singlefolder";
      }
      return new Folder($id, $name, "", 1);
    }

    /**
     * Helper function to get pseudo parent id of given folder
     *
     * @param integer $id Folder id to get parent
     * @return int|NULL
     */
    public function getFolderParent($id)
    {
      $pid = null;
      if ($id == 3 || $id == 4) {
        $pid = 2;
      } elseif ($id > 4) {
        $pid = $id - 1;
      }
      return $pid;
    }

    /**
     * Helper function to get JSON array from response
     *
     * @param Response $response
     * @return array Decoded response
     */
    private function getResponseJson($response)
    {
      $response->getBody()->seek(0);
      return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @test
     * -# Test to get all folders accessible by user by calling
     *    FolderController::getFolders()
     * -# Check if the response is array of folders
     */
    public function testGetAllFolders()
    {
      $rootFolder = new Folder(2, "root", "", 2);
      $this->folderDao->shouldReceive('getRootFolder')->withArgs(array(2))
        ->andReturn($rootFolder);
      $this->folderDao->shouldReceive('getFolder')
        ->andReturnUsing([$this, 'getFolder']);
      $this->folderDao->shouldReceive('getFolderParentId')
        ->andReturnUsing([$this, 'getFolderParent']);
      $expectedFoldersList = [];
      for ($i = 2; $i <= 4; $i ++) {
        $folder = $this->getFolder($i);
        $parentId = $this->getFolderParent($i);
        $folderModel = new \Fossology\UI\Api\Models\Folder($folder->getId(),
          $folder->getName(), $folder->getDescription(), $parentId);
        $expectedFoldersList[] = $folderModel->getArray();
      }
      $actualResponse = $this->folderController->getFolders(null,
        new ResponseHelper(), []);
      $this->assertEquals(200, $actualResponse->getStatusCode());
      $this->assertEquals($expectedFoldersList,
        $this->getResponseJson($actualResponse));
    }

    /**
     * @test
     *    FolderController::getFolders()
     * -# Check if the response is an HttpNotFoundException
     */
    public function testGetAllFoldersNotFound()
    {
      $folderId = 1;
      $rootFolder = new Folder(2, "root", "", 2);
      $this->folderDao->shouldReceive('getRootFolder')->withArgs(array(2))
        ->andReturn($rootFolder);
      $this->folderDao->shouldReceive('getFolder')
        ->andReturnUsing([$this, 'getFolder']);
      $this->folderDao->shouldReceive('isFolderAccessible')->withArgs([$folderId])->andReturn(false);
      $this->folderDao->shouldReceive('getFolderParentId')
        ->andReturnUsing([$this, 'getFolderParent']);
      $this->expectException(HttpForbiddenException::class);
      $this->folderController->getFolders(null,
        new ResponseHelper(), ["id"=>1]);
    }



    /**
     * @test
     * -# Test to get specific folder from FolderController::getFolders()
     * -# Check if the response is a single object of Folder
     */
    public function testGetSpecificFolders()
    {
      $folderId = 3;
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs(array($folderId))->andReturn(true);
      $this->folderDao->shouldReceive('getFolder')
        ->andReturnUsing([$this, 'getFolder']);
      $this->folderDao->shouldReceive('getFolderParentId')
        ->andReturnUsing([$this, 'getFolderParent']);
      $folder = $this->getFolder($folderId);
      $parentId = $this->getFolderParent($folderId);
      $folderModel = new \Fossology\UI\Api\Models\Folder($folder->getId(),
        $folder->getName(), $folder->getDescription(), $parentId);
      $expectedFoldersList = $folderModel->getArray();
      $actualResponse = $this->folderController->getFolders(null,
        new ResponseHelper(), ['id' => $folderId]);
      $this->assertEquals(200, $actualResponse->getStatusCode());
      $this->assertEquals($expectedFoldersList,
        $this->getResponseJson($actualResponse));
    }


    /**
     * @test
     * -# Test to check a 403 response of invalid folder
     * -# Call FolderController::getFolders() for invalid folder
     * -# Check for a 403 response
     */
    public function testGetInvalidFolder()
    {
      $folderId = -1;
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs(array($folderId))->andReturn(false);
      $this->folderDao->shouldReceive('getFolder')
        ->andReturnUsing([$this, 'getFolder']);
      $this->expectException(HttpForbiddenException::class);

      $this->folderController->getFolders(null, new ResponseHelper(),
        ['id' => $folderId]);
    }

    /**
     * @test
     * -# Test to check inaccessible folder's response on
     *    FolderController::getFolders()
     * -# Check for 403 response
     */
    public function testGetInAccessibleFolder()
    {
      $folderId = 3;
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs(array($folderId))->andReturn(false);
      $this->folderDao->shouldReceive('getFolder')
        ->andReturnUsing([$this, 'getFolder']);
      $this->expectException(HttpForbiddenException::class);

      $this->folderController->getFolders(null, new ResponseHelper(),
        ['id' => $folderId]);
    }

    /**
     * @test
     * -# Check for FolderController::createFolder() with version 1 attributes
     * -# Check for 201 response with folder id
     */
    public function testCreateFolderV1()
    {
      $this->testCreateFolder(ApiVersion::V1);
    }
    /**
     * @test
     * -# Check for FolderController::createFolder() with version 2 attributes
     * -# Check for 201 response with folder id
     */
    public function testCreateFolderV2()
    {
      $this->testCreateFolder();
    }

    /**
     * @param $version to test
     * @return void
     */
    private  function testCreateFolder($version = ApiVersion::V2)
    {
      $parentFolder = 2;
      $folderName = "root-child1";
      $folderDescription = "Description";
      $folderId = 5;
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs(array($parentFolder, $this->userId))->andReturn(true);
      $this->folderPlugin->shouldReceive('create')
        ->withArgs(array($parentFolder, $folderName, $folderDescription))
        ->andReturn(1);
      $this->folderDao->shouldReceive('getFolderId')
        ->withArgs(array($folderName, $parentFolder))->andReturn($folderId);
      $requestHeaders = new Headers();
      $body = $this->streamFactory->createStream();
      $request = new Request("POST", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $body);
      if ($version == ApiVersion::V2) {
        $request = $request->withQueryParams(['parentFolder'=>$parentFolder, 'folderName'=>$folderName, 'folderDescription'=>$folderDescription]);
      } else {
        $request = $request->withHeader('parentFolder', $parentFolder)
          ->withHeader('folderName', $folderName)
          ->withHeader('folderDescription', $folderDescription);
      }
      $request = $request->withAttribute(ApiVersion::ATTRIBUTE_NAME, $version);
      $response = new ResponseHelper();
      $actualResponse = $this->folderController->createFolder($request,
        $response, []);
      $expectedResponse = new Info(201, $folderId, InfoType::INFO);
      $this->assertEquals($expectedResponse->getCode(),
        $actualResponse->getStatusCode());
      $this->assertEquals($expectedResponse->getArray(),
        $this->getResponseJson($actualResponse));
      $this->assertEquals($expectedResponse->getArray(),$this->getResponseJson($actualResponse));
    }

    /**
     * @test
     * -# check for Bad request exception.
     */

    public function testCreateFolderInvalidBody()
    {
      $requestHeaders = new Headers();
      $body = $this->streamFactory->createStream("invalid_json"); // Simulate invalid JSON
      $request = new Request("POST", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $body);
      $response = new ResponseHelper();

      $this->expectException(HttpBadRequestException::class);

      $this->folderController->createFolder($request, $response, []);
    }

    /**
     * @test
     * -# Check for inaccessible parent on FolderController::createFolder() with version 1 attributes
     * -# Check for 403 response
     */
    public function testCreateFolderParentNotAccessibleV1()
    {
      $this->testCreateFolderParentNotAccessible(ApiVersion::V1);
    }
    /**
     * @test
     * -# Check for inaccessible parent on FolderController::createFolder() with version 1 attributes
     * -# Check for 403 response
     */
    public function testCreateFolderParentNotAccessibleV2()
    {
      $this->testCreateFolderParentNotAccessible();
    }

    /**
     * @param $version to test
     * @return void
     */
    private function testCreateFolderParentNotAccessible($version = ApiVersion::V2)
    {
      $parentFolder = 2;
      $folderName = "root-child1";
      $folderDescription = "Description";
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs(array($parentFolder, $this->userId))->andReturn(false);
      $requestHeaders = new Headers();
      $body = $this->streamFactory->createStream();
      $request = new Request("POST", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $body);
      if ($version == ApiVersion::V2) {
        $request = $request->withQueryParams(['parentFolder'=>$parentFolder, 'folderName'=>$folderName, 'folderDescription'=>$folderDescription]);
      } else {
        $request = $request->withHeader('parentFolder', $parentFolder)
          ->withHeader('folderName', $folderName)
          ->withHeader('folderDescription', $folderDescription);
      }
      $request = $request->withAttribute(ApiVersion::ATTRIBUTE_NAME, $version);
      $response = new ResponseHelper();
      $this->expectException(HttpForbiddenException::class);
      $this->folderController->createFolder($request, $response, []);
    }

    /**
     * @test FolderController::createFolder() with version 1 attributes
     * -# Check for duplicate folder response from
     *    FolderController::createFolder()
     * -# Check for 200 response
     */
    public function testCreateFolderDuplicateNamesV1()
    {
      $this->testCreateFolderDuplicateNames(ApiVersion::V1);
    }
    /**
     * @test FolderController::createFolder() with version 2 attributes
     * -# Check for duplicate folder response from
     *    FolderController::createFolder()
     * -# Check for 200 response
     */
    public function testCreateFolderDuplicateNamesV2()
    {
      $this->testCreateFolderDuplicateNames();
    }
    /**
     * @param $version to test
     * @return void
     */
    private function testCreateFolderDuplicateNames($version = ApiVersion::V2)
    {
      $parentFolder = 2;
      $folderName = "root-child1";
      $folderDescription = "Description";
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs(array($parentFolder, $this->userId))->andReturn(true);
      $this->folderPlugin->shouldReceive('create')
        ->withArgs(array($parentFolder, $folderName, $folderDescription))
        ->andReturn(4);
      $requestHeaders = new Headers();
      $requestHeaders->setHeader('parentFolder', $parentFolder);
      $requestHeaders->setHeader('folderName', $folderName);
      $requestHeaders->setHeader('folderDescription', $folderDescription);
      $body = $this->streamFactory->createStream();
      $request = new Request("POST", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $body);
      if ($version == ApiVersion::V2) {
        $request = $request->withQueryParams(['parentFolder'=>$parentFolder,'folderName'=>$folderName, 'folderDescription'=>$folderDescription]);
      } else {
        $request = $request->withHeader('parentFolder', $parentFolder)
          ->withHeader('folderName', $folderName)
          ->withHeader('folderDescription', $folderDescription);
      }
      $request = $request->withAttribute(ApiVersion::ATTRIBUTE_NAME, $version);
      $response = new ResponseHelper();
      $actualResponse = $this->folderController->createFolder($request,
        $response, []);
      $expectedResponse = new Info(200, "Folder $folderName already exists!",
        InfoType::INFO);
      $this->assertEquals($expectedResponse->getCode(),
        $actualResponse->getStatusCode());
      $this->assertEquals($expectedResponse->getArray(),
        $this->getResponseJson($actualResponse));
    }

    /**
     * @test
     * -# Test for FolderController::deleteFolder()
     * -# Check for 202 response
     */
    public function testDeleteFolder()
    {
      $folderId = 3;
      $folderName = \Fossology\UI\Api\Controllers\FolderGetName($folderId);
      $this->folderDao->shouldReceive('getFolder')
        ->withArgs(array($folderId))->andReturn($this->getFolder($folderId));
      $this->deletePlugin->shouldReceive('Delete')
        ->withArgs(array("2 $folderId", $this->userId))->andReturnNull();
      $actualResponse = $this->folderController->deleteFolder(null,
        new ResponseHelper(), ["id" => $folderId]);
      $expectedResponse = new Info(202, "Folder, \"$folderName\" deleted.",
        InfoType::INFO);
      $this->assertEquals($expectedResponse->getCode(),
        $actualResponse->getStatusCode());
      $this->assertEquals($expectedResponse->getArray(),
        $this->getResponseJson($actualResponse));
    }

    /**
     * @test
     * -# Test if folder is invalid on FolderController::deleteFolder()
     * -# Check for 404 response
     */
    public function testDeleteFolderInvalidFolder()
    {
      $folderId = 0;
      $this->folderDao->shouldReceive('getFolder')
        ->withArgs(array($folderId))->andReturnNull();
      $this->expectException(HttpNotFoundException::class);

      $this->folderController->deleteFolder(null, new ResponseHelper(),
        ["id" => $folderId]);
    }

    /**
     * @test
     * -# Test to delete inaccessible folder on FolderController::deleteFolder()
     * -# Check for 403 response
     */
    public function testDeleteFolderNoAccess()
    {

      $folderId = 3;
      $errorText = "No access to delete this folder";
      $this->folderDao->shouldReceive('getFolder')
        ->withArgs(array($folderId))->andReturn($this->getFolder($folderId));
      $this->deletePlugin->shouldReceive('Delete')
        ->withArgs(array("2 $folderId", $this->userId))
        ->andReturn($errorText);
      $this->expectException(HttpForbiddenException::class);

      $this->folderController->deleteFolder(null, new ResponseHelper(),
        ["id" => $folderId]);
    }

    /**
     * @test
     * -# Test for FolderController::editFolder() with version 1 attributes
     * -# Check for 200 response
     */
    public function testEditFolderV1()
    {
      $this->testEditFolder(APiVersion::V1);
    }
    /**
     * @test
     * -# Test for FolderController::editFolder() with version 2 attributes
     * -# Check for 200 response
     */
    public function testEditFolderV2()
    {
      $this->testEditFolder();
    }

    /**
     * @param $version to test
     * @return void
     */
    private function testEditFolder($version = ApiVersion::V2)
    {
      $folderId = 3;
      $folderName = "new name";
      $folderDescription = "new description";
      $this->folderDao->shouldReceive('getFolder')
        ->andReturnUsing([$this, 'getFolder']);
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs(array($folderId, $this->userId))->andReturn(true);
      $this->folderPropertiesPlugin->shouldReceive('Edit')
        ->withArgs(array($folderId, $folderName, $folderDescription));
      $requestHeaders = new Headers();
      $body = $this->streamFactory->createStream();
      $request = new Request("PATCH", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $body);
      if ($version == ApiVersion::V2) {
        $request = $request->withQueryParams(['name'=> $folderName, 'description'=>$folderDescription]);
      } else {
        $request = $request->withHeader('name', $folderName)
          ->withHeader('description', $folderDescription);
      }
      $request = $request->withAttribute(ApiVersion::ATTRIBUTE_NAME, $version);
      $response = new ResponseHelper();
      $actualResponse = $this->folderController->editFolder($request,
        $response, ["id" => $folderId]);
      $expectedResponse = new Info(200, 'Folder "' . \Fossology\UI\Api\Controllers\FolderGetName($folderId) .
        '" updated.', InfoType::INFO);
      $this->assertEquals($expectedResponse->getCode(),
        $actualResponse->getStatusCode());
      $this->assertEquals($expectedResponse->getArray(),
        $this->getResponseJson($actualResponse));
    }

    /**
     * @test
     * -# Check FolderController::editFolder() on non-existing folder with version 1 attributes
     * -# Check for 404 response
     */
    public function testEditFolderNotExistsV1()
    {
      $this->testEditFolderNotExists(ApiVersion::V1);
    }
    /**
     * @test
     * -# Check FolderController::editFolder() on non-existing folder with version 2 attributes
     * -# Check for 404 response
     */
    public function testEditFolderNotExistsV2()
    {
      $this->testEditFolderNotExists();
    }
    private function testEditFolderNotExists($version = ApiVersion::V2)
    {
      $folderId = 8;
      $folderName = "new name";
      $folderDescription = "new description";
      $this->folderDao->shouldReceive('getFolder')->andReturnNull();
      $requestHeaders = new Headers();
      $requestHeaders->setHeader('name', $folderName);
      $requestHeaders->setHeader('description', $folderDescription);
      $body = $this->streamFactory->createStream();
      $request = new Request("PATCH", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $body);
      if ($version == ApiVersion::V2) {
        $request = $request->withQueryParams(['name'=> $folderName, 'description'=>$folderDescription]);
      } else {
        $request = $request->withHeader('name', $folderName)
          ->withHeader('description', $folderDescription);
      }
      $request = $request->withAttribute(ApiVersion::ATTRIBUTE_NAME, $version);
      $response = new ResponseHelper();
      $this->expectException(HttpNotFoundException::class);

      $this->folderController->editFolder($request, $response,
        ["id" => $folderId]);
    }
    /**
     * @test
     * -# Test for inaccessible folder on FolderController::editFolder() with version 1 attributes
     * -# Check for 403 response
     */
    public function testEditFolderNotAccessibleV1()
    {
      $this->testEditFolderNotAccessible(APIVersion::V2);
    }
    /**
     * @test
     * -# Test for inaccessible folder on FolderController::editFolder() with version 2 attributes
     * -# Check for 403 response
     */
    public function testEditFolderNotAccessibleV2()
    {
      $this->testEditFolderNotAccessible();
    }
    /**
     * @param $version to test
     * @return void
     */
    private function testEditFolderNotAccessible($version = ApiVersion::V2)
    {
      $folderId = 3;
      $folderName = "new name";
      $folderDescription = "new description";
      $this->folderDao->shouldReceive('getFolder')
        ->andReturnUsing([$this, 'getFolder']);
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs(array($folderId, $this->userId))->andReturn(false);
      $requestHeaders = new Headers();
      $requestHeaders->setHeader('name', $folderName);
      $requestHeaders->setHeader('description', $folderDescription);
      $body = $this->streamFactory->createStream();
      $request = new Request("PATCH", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $body);
      if ($version == ApiVersion::V2) {
        $request = $request->withQueryParams(['name'=> $folderName, 'description'=>$folderDescription]);
      } else {
        $request = $request->withHeader('name', $folderName)
          ->withHeader('description', $folderDescription);
      }
      $request = $request->withAttribute(ApiVersion::ATTRIBUTE_NAME, $version);
      $response = new ResponseHelper();
      $this->expectException(HttpForbiddenException::class);

      $this->folderController->editFolder($request, $response,
        ["id" => $folderId]);
    }

    /**
     * @test
     * -# Test for copy action on FolderController::copyFolder() with version 1 attributes
     * -# Check for 202 response
     */
    public function testCopyFolderV1()
    {
      $this->testCopyFolder(APiVersion::V1);
    }
    /**
     * @test
     * -# Test for copy action on FolderController::copyFolder() with version 2 attributes
     * -# Check for 202 response
     */
    public function testCopyFolderV2()
    {
      $this->testCopyFolder();
    }
    /**
     * @param $version to test
     * @return void
     */
    private function testCopyFolder($version = ApiVersion::V2)
    {
      $folderId = 3;
      $parentId = 2;
      $folderContentPk = 5;
      $folderName = \Fossology\UI\Api\Controllers\FolderGetName($folderId);
      $parentFolderName = \Fossology\UI\Api\Controllers\FolderGetName($parentId);

      $this->folderDao->shouldReceive('getFolder')
        ->andReturnUsing([$this, 'getFolder']);
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs(array(M::anyOf($folderId, "$parentId"),
          $this->userId))->andReturn(true);
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs([$parentId,
          $this->userId])->andReturn(true);
      $this->folderDao->shouldReceive('getFolderContentsId')
        ->withArgs(array($folderId, 1))->andReturn($folderContentPk);
      $this->folderContentPlugin->shouldReceive('copyContent')
        ->withArgs(array([$folderContentPk], $parentId, true))->andReturn("");

      $requestHeaders = new Headers();
      $body = $this->streamFactory->createStream();
      $request = new Request("PUT", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $body);
      if ($version == ApiVersion::V2) {
        $request = $request->withQueryParams(['parent'=> $parentId, 'action'=>"copy"]);
      } else {
        $request = $request->withHeader('parent', $parentId)
          ->withHeader('action', "copy");
      }
      $request = $request->withAttribute(ApiVersion::ATTRIBUTE_NAME, $version);
      $response = new ResponseHelper();

      $actualResponse = $this->folderController->copyFolder($request,
        $response, ["id" => $folderId]);
      $expectedResponse = new Info(202,
        "Folder \"$folderName\" copy(ed) under \"$parentFolderName\".",
        InfoType::INFO);
      $this->assertEquals($expectedResponse->getCode(),
        $actualResponse->getStatusCode());
      $this->assertEquals($expectedResponse->getArray(),
        $this->getResponseJson($actualResponse));
    }

    /**
     * @test
     * -# Test for move action on FolderController::copyFolder() with version 1 attributes
     * -# Check for 202 response
     */
    public function testMoveFolderV1()
    {
      $this->testMoveFolder(ApiVersion::V1);
    }
    /**
     * @test
     * -# Test for move action on FolderController::copyFolder() with version 2 attributes
     * -# Check for 202 response
     */
    public function testMoveFolderV2()
    {
      $this->testMoveFolder();
    }

    /**
     * @param $version to test
     * @return void
     */
    private function testMoveFolder($version = ApiVersion::V2)
    {
      $folderId = 3;
      $parentId = 2;
      $folderContentPk = 5;
      $folderName = \Fossology\UI\Api\Controllers\FolderGetName($folderId);
      $parentFolderName = \Fossology\UI\Api\Controllers\FolderGetName($parentId);

      $this->folderDao->shouldReceive('getFolder')
        ->andReturnUsing([$this, 'getFolder']);
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs(array(M::anyOf($folderId, "$parentId"),
          $this->userId))->andReturn(true);
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs([$parentId, $this->userId])->andReturn(true);
      $this->folderDao->shouldReceive('getFolderContentsId')
        ->withArgs(array($folderId, 1))->andReturn($folderContentPk);
      $this->folderContentPlugin->shouldReceive('copyContent')
        ->withArgs(array([$folderContentPk], $parentId, false))->andReturn("");
      $requestHeaders = new Headers();
      $body = $this->streamFactory->createStream();
      $request = new Request("PUT", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $body);
      $response = new ResponseHelper();
      if ($version == ApiVersion::V2) {
        $request = $request->withQueryParams(['parent'=>$parentId, 'action'=>'move']);
      } else {
        $request = $request->withHeader('parent', $parentId)
          ->withHeader('action', "move");
      }
      $request = $request->withAttribute(ApiVersion::ATTRIBUTE_NAME,$version);
      $actualResponse = $this->folderController->copyFolder($request,
        $response, ["id" => $folderId]);
      $expectedResponse = new Info(202,
        "Folder \"$folderName\" move(ed) under \"$parentFolderName\".",
        InfoType::INFO);
      $this->assertEquals($expectedResponse->getCode(),
        $actualResponse->getStatusCode());
      $this->assertEquals($expectedResponse->getArray(),
        $this->getResponseJson($actualResponse));
    }

    /**
     * @test
     * -# Test for invalid folder copy on FolderController::copyFolder() with version 1 attributes
     * -# Check for 404 response
     */
    public function testCopyFolderNotFoundV1()
    {
      $this->testCopyFolderNotFound(APiVersion::V2);
    }
    /**
     * @test
     * -# Test for invalid folder copy on FolderController::copyFolder() with version 2 attributes
     * -# Check for 404 response
     */
    public function testCopyFolderNotFoundV2()
    {
      $this->testCopyFolderNotFound();
    }
    private function testCopyFolderNotFound($version = ApiVersion::V2)
    {
      $folderId = 3;
      $parentId = 2;

      $this->folderDao->shouldReceive('getFolder')->withArgs(array($folderId))
        ->andReturnNull();
      $requestHeaders = new Headers();
      $body = $this->streamFactory->createStream();
      $request = new Request("PUT", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $body);
      if ($version == ApiVersion::V2) {
        $request = $request->withQueryParams(['parent'=>$parentId, 'action'=>'copy']);
      } else {
        $request = $request->withHeader('parent', $parentId)
          ->withHeader('action', "copy");
      }
      $request = $request->withAttribute(ApiVersion::ATTRIBUTE_NAME,$version);
      $response = new ResponseHelper();
      $this->expectException(HttpNotFoundException::class);

      $this->folderController->copyFolder($request, $response,
        ["id" => $folderId]);
    }

    /**
     * @test
     * -# Test for invalid parent copy on FolderController::copyFolder() with version 1 attributes
     * -# Check for 404 response
     */
    public function testCopyParentFolderNotFoundV1()
    {
      $this->testCopyParentFolderNotFound(APiVersion::V1);
    }
    /**
     * @test
     * -# Test for invalid parent copy on FolderController::copyFolder() with version 2 attributes
     * -# Check for 404 response
     */
    public function testCopyParentFolderNotFoundV2()
    {
      $this->testCopyParentFolderNotFound();
    }
    private function testCopyParentFolderNotFound($version = ApiVersion::V2)
    {
      $folderId = 3;
      $parentId = 2;

      $this->folderDao->shouldReceive('getFolder')->withArgs(array($folderId))
        ->andReturn($this->getFolder($folderId));
      $this->folderDao->shouldReceive('getFolder')->withArgs(array($parentId))
        ->andReturnNull();
      $requestHeaders = new Headers();
      $requestHeaders->setHeader('parent', $parentId);
      $requestHeaders->setHeader('action', "copy");
      $body = $this->streamFactory->createStream();
      $request = new Request("PUT", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $body);
      if ($version == ApiVersion::V2) {
        $request = $request->withQueryParams(['parent'=>$parentId, 'action'=>'copy']);
      } else {
        $request = $request->withHeader('parent', $parentId)
          ->withHeader('action', "copy");
      }
      $request = $request->withAttribute(ApiVersion::ATTRIBUTE_NAME,$version);
      $response = new ResponseHelper();
      $this->expectException(HttpNotFoundException::class);

      $this->folderController->copyFolder($request, $response,
        ["id" => $folderId]);
    }

    /**
     * @test
     * -# Test for inaccessible folder on FolderController::copyFolder() with version 1 attributes
     * -# Check for 403 response
     */
    public function testCopyFolderNotAccessibleV1()
    {
      $this->testCopyFolderNotAccessible(ApiVersion::V1);
    }
    /**
     * @test
     * -# Test for inaccessible folder on FolderController::copyFolder() with version 2 attributes
     * -# Check for 403 response
     */
    public function testCopyFolderNotAccessibleV2()
    {
      $this->testCopyFolderNotAccessible();
    }

    /**
     * @param $version to test
     * @return void
     */
    private function testCopyFolderNotAccessible($version = ApiVersion::V2)
    {
      $folderId = 3;
      $parentId = 2;

      $this->folderDao->shouldReceive('getFolder')
        ->andReturnUsing([$this, 'getFolder']);
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs(array($folderId, $this->userId))->andReturn(false);
      $requestHeaders = new Headers();
      $body = $this->streamFactory->createStream();
      $request = new Request("PUT", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $body);
      if ($version == ApiVersion::V2) {
        $request = $request->withQueryParams(['parent'=>$parentId, 'action'=>'copy']);
      } else {
        $request = $request->withHeader('parent', $parentId)
          ->withHeader('action', "copy");
      }
      $request = $request->withAttribute(ApiVersion::ATTRIBUTE_NAME,$version);
      $response = new ResponseHelper();
      $this->expectException(HttpForbiddenException::class);

      $this->folderController->copyFolder($request, $response,
        ["id" => $folderId]);
    }

    /**
     * @test
     * -# Test for inaccessible parent folder on FolderController::copyFolder() with version 1 attributes
     * -# Check for 403 response
     */
    public function testCopyParentFolderNotAccessibleV1()
    {
      $this->testCopyParentFolderNotAccessible(ApiVersion::V1);
    }
    /**
     * @test
     * -# Test for inaccessible parent folder on FolderController::copyFolder() with version 2 attributes
     * -# Check for 403 response
     */
    public function testCopyParentFolderNotAccessibleV2()
    {
      $this->testCopyParentFolderNotAccessible();
    }
    private function testCopyParentFolderNotAccessible($version = ApiVersion::V2)
    {
      $folderId = 3;
      $parentId = 2;

      $this->folderDao->shouldReceive('getFolder')
        ->andReturnUsing([$this, 'getFolder']);
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs(array($folderId, $this->userId))->andReturn(true);
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs(array("$parentId", $this->userId))->andReturn(false);
      $requestHeaders = new Headers();
      $body = $this->streamFactory->createStream();
      $request = new Request("PUT", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $body);
      if ($version == ApiVersion::V2) {
        $request = $request->withQueryParams(['parent'=>$parentId, 'action'=>'copy']);
      } else {
        $request = $request->withHeader('parent', $parentId)
          ->withHeader('action', "copy");
      }
      $request = $request->withAttribute(ApiVersion::ATTRIBUTE_NAME,$version);
      $response = new ResponseHelper();
      $this->expectException(HttpForbiddenException::class);

      $this->folderController->copyFolder($request, $response,
        ["id" => $folderId]);
    }

    /**
     * @test
     * -# Test for invalid action on FolderController::copyFolder() with version 1 attributes
     * -# Check for 400 response
     */
    public function testCopyFolderInvalidActionV1()
    {
      $this->testCopyFolderInvalidAction(ApiVersion::V1);
    }
    /**
     * @test
     * -# Test for invalid action on FolderController::copyFolder() with version 2 attributes
     * -# Check for 400 response
     */
    public function testCopyFolderInvalidActionV2()
    {
      $this->testCopyFolderInvalidAction();
    }


    /**
     * @param $version to test
     * @return void
     */
    private function testCopyFolderInvalidAction($version = ApiVersion::V2)
    {
      $folderId = 3;
      $parentId = 2;
      $contentId = 1;
      $this->folderDao->shouldReceive('removeContent')->withArgs(array($contentId))->andReturn(false);

      $this->folderDao->shouldReceive('getFolder')
        ->andReturnUsing([$this, 'getFolder']);
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs(array(M::anyOf($folderId, "$parentId"),
          $this->userId))->andReturn(true);
      $this->folderDao->shouldReceive('isFolderAccessible')
        ->withArgs([$parentId,$this->userId])->andReturn(true);
      $requestHeaders = new Headers();
      $body = $this->streamFactory->createStream();
      $request = new Request("PUT", new Uri("HTTP", "localhost"),
        $requestHeaders, [], [], $body);
      if ($version == ApiVersion::V2) {
        $request = $request->withQueryParams(['parent'=>$parentId, 'action'=>'somethingrandom']);
      } else {
        $request = $request->withHeader('parent', $parentId)
          ->withHeader('action', "somethingrandom");
      }
      $request = $request->withAttribute(ApiVersion::ATTRIBUTE_NAME,$version);
      $response = new ResponseHelper();
      $this->expectException(HttpBadRequestException::class);

      $this->folderController->copyFolder($request, $response,
        ["id" => $folderId]);
    }

    /**
     * @test
     * -# Test for invalid action on FolderController::unlinkFolder()
     * -# Check if the HttpNotFoundException is thrown
     */

    public function testUnlinkFolderFolderNotFound()
    {
      $contentId = 4;
      $this->dbHelper->shouldReceive("doesIdExist")->withArgs(array("foldercontents","foldercontents_pk", $contentId))->andReturn(false);

      $requestHeaders = new Headers();
      $body = $this->streamFactory->createStream();
      $request = new Request("PUT", new Uri("HTTP", "localhost"),$requestHeaders, [],[],$body);
      $this->expectException(HttpNotFoundException::class);
      $this->folderController->unlinkFolder($request, new ResponseHelper(),['contentId' => $contentId]);

    }

    /**
     * @test
     * -# Test for invalid action on FolderController::unlinkFolder()
     * -# Check if the HttpNotFoundException is thrown
     */
    public function testUnlinkUnExistingFolder()
    {
      $contentId = 4;
      $this->dbHelper->shouldReceive("doesIdExist")->withArgs(array("foldercontents","foldercontents_pk", $contentId))->andReturn(false);

      $requestHeaders = new Headers();
      $body = $this->streamFactory->createStream();
      $request = new Request("PUT", new Uri("HTTP", "localhost"),$requestHeaders, [],[],$body);
      $this->expectException(HttpNotFoundException::class);
      $this->folderController->unlinkFolder(null, new ResponseHelper(),['contentId' => $contentId]);

    }

    /**
     * @test
     * -# Test for invalid action on FolderController::getAllFolderContents()
     * -# Check if the statusCode is 200
     */
    public  function testGetAllFolderContents()
    {
      $folderId = 2;
      $folder = new Folder($folderId,"Data","Folding for testing",1);
      $this->restHelper->shouldReceive('getUserId')->andReturn($this->userId);
      $this->folderDao->shouldReceive('isFolderAccessible')->andReturn(true);
      $this->folderDao->shouldReceive('getFolder')->andReturn($folder);
      $this->folderContentPlugin->shouldReceive('handle')->andReturn([]);
      $this->folderDao->shouldReceive('getRemovableContents')->withArgs(array($folderId))->andReturn([]);

      $actualResponse = $this->folderController->getAllFolderContents(null,new ResponseHelper(),['id' => $folderId]);
      $this->assertEquals(200, $actualResponse->getStatusCode());
    }

    /**
     * @test
     * -# Test for invalid action on FolderController::getAllFolderContents()
     * -# Check if the HttpNotFoundException is thrown
     */
    public  function testGetAllFolderContentsNotFound()
    {
      $folderId = 2;
      $this->folderDao->shouldReceive('getFolder')->andReturn(null);

      $this->expectException(HttpNotFoundException::class);
      $this->folderController->getAllFolderContents(null,new ResponseHelper(),['id' => $folderId]);
    }

    /**
     * @test
     * -# Test for invalid action on FolderController::getAllFolderContents()
     * -# Check if the HttpForbiddenException is thrown
     */
    public  function testGetAllInAccessibleFolderContents()
    {

      $folderId = 2;
      $folder = new Folder($folderId,"Data","Folding for testing",1);
      $this->folderDao->shouldReceive('isFolderAccessible')->andReturn(false);
      $this->folderDao->shouldReceive('getFolder')->andReturn($folder);

      $this->expectException(HttpForbiddenException::class);
      $this->folderController->getAllFolderContents(null,new ResponseHelper(),['id' => $folderId]);
    }

    /**
     * @test
     * -# Test for invalid action on FolderController::getUnlinkableFolderContents()
     * -# Check if the statusCode is 200
     */
    public function testGetUnlinkableFolderContents()
    {
      $folderId = 3;
      $id = 3;
      $userId = 2;
      $folder = new Folder($folderId,"Data","Folding for testing",1);
      $this->folderDao->shouldReceive('getFolder')->withArgs(array($folderId))->andReturn($folder);
      $this->restHelper->shouldReceive("getUserId")->andReturn($userId);
      $this->folderDao->shouldReceive('isFolderAccessible')->withArgs(array($folderId, $this->restHelper->getUserId()))->andReturn(true);
      $this->restHelper->shouldReceive('getFolderDao')->andReturn($this->folderDao);
      $this->folderContentPlugin->shouldReceive('handle');

      $actualResponse = $this->folderController->getUnlinkableFolderContents(null,new ResponseHelper(),["id" => $id]);
      var_dump($actualResponse);
      $this->assertEquals(200,$actualResponse->getStatusCode());
    }

    /**
     * @test
     * -# Test for invalid action on FolderController::getUnlinkableFolderContents()
     * -# Check if the HttpForbiddenException is thrown
     */
    public function testGetUnlinkableInaccessibleFolderContents()
    {
      $folderId = 3;
      $id = 3;
      $folder = new Folder($folderId,"Data","Folding for testing",1);
      $this->folderDao->shouldReceive('getFolder')->withArgs(array($folderId))->andReturn($folder);
      $this->folderDao->shouldReceive('isFolderAccessible')->withArgs(array($folderId, $this->restHelper->getUserId()))->andReturn(false);
      $this->restHelper->shouldReceive('getFolderDao')->andReturn($this->folderDao);
      $this->folderContentPlugin->shouldReceive('handle');

      $this->expectException(HttpForbiddenException::class);
      $this->folderController->getUnlinkableFolderContents(null,new ResponseHelper(),["id" => $id]);
    }

    /**
     * @test
     * -# Test for invalid action on FolderController::getUnlinkableFolderContents()
     * -# Check if the HttpNotFoundException is thrown
     */
    public function testGetUnlinkableFolderContentsBadRequest()
    {
      $folderId = 3;
      $id = 3;
      $this->folderDao->shouldReceive('getFolder')->withArgs(array($folderId))->andReturn(null);
      $this->folderDao->shouldReceive('isFolderAccessible')->withArgs(array($folderId, $this->restHelper->getUserId()))->andReturn(false);
      $this->restHelper->shouldReceive('getFolderDao')->andReturn($this->folderDao);

      $this->expectException(HttpNotFoundException::class);
      $this->folderController->getUnlinkableFolderContents(null,new ResponseHelper(),["id" => $id]);

    }
  }
}
