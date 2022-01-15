<?php 

$password = '1234' ;    // Please use a strong password  

// version 
define("VERSION", "0.1.2" );


session_start();    // open the session

ini_set('display_errors', 1);
error_reporting(E_ALL ^ E_NOTICE);  



// ----------------   check the action   ---------------

/* Check which page we want to access to */
$action = (isset($_GET['action']) && !empty($_GET['action'])) ? $_GET['action'] : 'index' ;

switch ($action) {
    case 'login':
        action_login();
        break;
        
    case 'logout':
        action_logout();
        break;
        
    case 'select':
        action_select();
        break;
        
    case 'scan':
        action_scan();
        break;
        
    default:
        action_index();
        break;
}



// -------------------   classes and functions    -------------------

/**
 * A class that represents a file or a directory
 */
class File{
    
    public $id ;
    private static $counter = 0;
    
    public $path;
    public $name;
    public $extension ;
    
    public $size = 0 ;
    public $sizeOnDisk = 0 ;  // because the space occupied on the disk is different from the real file size
    public $percent = 0;
    
    public $isDir = false;
    
    public $parent ;
    public $parentsIds = array() ;
    public $children ;
    public $depth = 0 ;
    
    public $itemsCount = 0 ;
    public $filesCount = 0 ;
    public $subdirsCount = 0 ;
    
    public $error = false ;
    
    public $lastModificationTime ; 
    
    
    /**
     * constructor
     * @param string $path absolute or relative path of the file
     */
    public function __construct($path){
        $this->id = self::$counter++;
        $this->path = $path;
        $this->name = basename($this->path);
        $this->extension = pathinfo($this->path, PATHINFO_EXTENSION);
        $this->isDir = is_dir($this->path) ;
        $this->size = ($this->isDir) ? 0: filesize($this->path);
        
        $stats = stat($path);
        if($stats){
            $this->sizeOnDisk = $stats['blocks'] * 512;
            $this->lastModificationTime = $stats['mtime'];
        } 
    }
    
    
    /**
     * Add a file to the folder
     * @param File $file 
     */
    public function addFile($file){
        if($this->children === null)
            $this->children = array() ;
        $this->children[]= $file ;
        // set depth
        $file->depth = $this->depth + 1 ;

        // set parent of the file
        $file->parent = $this ;
    }
    
}

/**
 * Check if we get to the page via GET or POST method 
 * @return boolean
 */
function is_post(){
    return strtoupper($_SERVER['REQUEST_METHOD']) == 'POST' ;
}


/**
 * Check if we have the permission to access to the page (after a login with a valid password)
 * @return boolean logged or not
 */
function has_permission(){
    if(!isset($_SESSION['wds_access']))
        return false;
    return $_SESSION['wds_access'] == "1" ;
}


/**
 * Check if we have the permission, if not we are redirected to login page
 */
function check_permissions(){
    if(!has_permission()){
        add_error_message("Please enter your password !") ;
        redirect_to_action("index");
    }
}

/**
 * Redirect to another page
 * @param string a GET action (e.g. "login")
 */
function redirect_to_action($action){  
    $url = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
    if($action != 'index')
        $url .= '?action='.$action;
   header('location: '.$url);
   exit();
}


/**
 * Check if the server is running on Windows
 * @return boolean
 */
function is_windows_os(){
    return PHP_OS == 'WINNT' ;
}
 
/**
 * Check if the server is running on Linux
 * @return boolean
 */
function is_linux_os(){
    return PHP_OS == 'Linux' ;
}

/**
 * Check if the server is running on MacOS
 * @return boolean
 */
function is_mac_os(){
    return PHP_OS == 'Darwin' ;
}


/**
 * Add alert error messages
 * @param string $message
 */
function add_error_message($message){
    if(!isset($_SESSION['wds_errors'])){
        $_SESSION['wds_errors'] = array();
    }
    $_SESSION['wds_errors'][]=$message ; // save the message in the session
}

/**
 * return all error messages
 * @return array messages
 */
function get_error_messages(){
    if(isset($_SESSION['wds_errors'])){
       return $_SESSION['wds_errors'];
    }
    return array();
}

/**
 * remove all error messages (after being displayed to the user)
 */
function clear_messages(){
    unset($_SESSION['wds_errors']);
}


/**
 * Format the size in bytes to "Human-readable" size with suffixes: B KB MB GB TB
 * for windows and Linux : 1KB = 1024 B
 * for MacOs : 1KB = 1000 B
 * @param integer $bytes the size in bytes
 * @return string the formatted size
 */
function format_file_size($bytes){
    if($bytes == 0)
        return "0 B";
    
    $base = 1024 ; // for Windows and Linux :   1KB = 1024 B
    
    // for MacOs
    if(is_mac_os()) 
        $base = 1000 ;  // for MacOs and Linux :   1KB = 1000 B
        
    $bytes = floatval($bytes);
    $arBytes = array(
        0 => array(
            "UNIT" => "TB",
            "VALUE" => pow($base, 4)
        ),
        1 => array(
            "UNIT" => "GB",
            "VALUE" => pow($base, 3)
        ),
        2 => array(
            "UNIT" => "MB",
            "VALUE" => pow($base, 2)
        ),
        3 => array(
            "UNIT" => "KB",
            "VALUE" => $base
        ),
        4 => array(
            "UNIT" => "B",
            "VALUE" => 1
        )
    );

    foreach ($arBytes as $arItem) {
        if ($bytes >= $arItem["VALUE"]) {
            $result = $bytes / $arItem["VALUE"];
            $result = strval(round($result, 1)) . " " . $arItem["UNIT"];
            break;
        }
    }
    return $result;
}

/**
 * get all files inside a directory. (this function is recursive)
 * This function is recursive and return the result in form of tree object
 * @param String $dir the directory to scan
 * @param Object $current_dir
 * @return File an Object of Class File that contains children files
 */
function get_dir_files($dir, &$current_dir = null) {
  
    // the root directory
    if($current_dir == null){
        $current_dir = new File($dir);
        $current_dir->percent = 100 ;
    }
    
    $files = @scandir($dir);
    
    if($files == false){
        add_error_message('You don\'t have permission to access this resource : "'.htmlspecialchars($dir).'"');
        $current_dir->error = true;
    }
    
    if(is_array($files)){
        foreach ($files as $filename) { 
            if ($filename == "." || $filename == "..")   // skip . and .. directories
                continue; 
    
            $path = realpath($dir . DIRECTORY_SEPARATOR . $filename);
            
            $file = new File($path);
    
            $current_dir->addFile($file);    // add the file to the folder
    
            // if is directory
            if ($file->isDir) {
                 get_dir_files($path, $file);
            }  
        
            $current_dir->size += $file->size ;  // add file size to the parent folder size 
            $current_dir->sizeOnDisk += $file->sizeOnDisk ;  // add the real size in disk to the parent folder size 
     
            // update parent folders items count
            $hierarchical_parent = $current_dir ;
            while($hierarchical_parent){
                if ($file->isDir){
                    $hierarchical_parent->subdirsCount++;
                }  else{
                    $hierarchical_parent->filesCount++;    
                }
                $hierarchical_parent->itemsCount++;
    
                // add all parents ids to a file
                if($hierarchical_parent->id !== null ){
                    $file->parentsIds[] = $hierarchical_parent->id;
                }
                
                // update parent
                $hierarchical_parent = $hierarchical_parent->parent ;
            }
        }
    }
    
    // calculate percent if the root directory
    if($current_dir->id === 0){
        update_tree($current_dir);
    }
 
    return $current_dir;
}

/**
 * Function to compare two files by size, it's used by usort() function to sort an array of Files
 * @param File $file1
 * @param File $file2
 * @return boolean
 */
function compare_files_size($file1, $file2){   
    // if the server use windows OS, because stat($path)['blocks'] return -1 on windows
    if (is_windows_os()){
        $size1 = $file1->size ;
        $size2 = $file2->size ;
    }else{
        $size1 = $file1->sizeOnDisk;
        $size2 = $file2->sizeOnDisk;
    }
    
    // if both files are the same size, return folders first 
    if( $size1 == $size2){
        
        // for small files smaller than the block size
        return $file2->size - $file1->size ;
    }
    
    return $size2 - $size1 ;
}

/**
 * Sort children files inside a tree
 * @param File $tree
 */
function sort_files_tree($tree) {
    if($tree->isDir && is_array($tree->children)){
        usort($tree->children, "compare_files_size" );
        
        foreach ($tree->children as $child) {
            if($child->isDir){
                sort_files_tree($child);
            }
        }
    } 
}


/**
 * Run the scan of a directory and get all files in a form of a tree
 * @param File $tree
 */
function scan_folder($path){
    $tree = get_dir_files($path);
    sort_files_tree($tree);
    
    return $tree ;
}

/**
 * Convert a tree of Files to a normal array of files (this function is recursive)
 * @param File $tree
 * @param array $data
 * @return array indexed array of files
 */
function tree_to_array($tree, &$data=array()){
    if(empty($data)){
        $data[]= $tree ;        
    }
    
    if(is_array($tree->children) && !empty($tree->children)){
        foreach ($tree->children as $file) {
            $data[]= $file;
            if($file->isDir){
                tree_to_array($file, $data);
            }
        }
    }
    
    return $data ;
}

/**
 * update the size percentage of all files in the tree (this function is recursive)
 * @param File $tree
 */
function update_tree($tree) {
    
    global $dirs_size_on_disk ;
    
    if($tree->isDir && is_array($tree->children)){
 
        foreach ($tree->children as $file) {
            
            // update size percent
            if (is_windows_os()){ // if windows
                if($tree->size != 0) // to avoid Division by zero
                    $file->percent = ($file->size * 100) / $tree->size ;
            }else{
                
                // on linux
                if($dirs_size_on_disk === null){
                    $stats = stat($tree->path);
                    $dirs_size_on_disk  = $stats['blocks'] * 512;
                }
                
                if($tree->sizeOnDisk-$dirs_size_on_disk != 0){    // to avoid division by zero
                    $file->percent = ($file->sizeOnDisk * 100) / ($tree->sizeOnDisk - $dirs_size_on_disk) ;
                }    
            }
 
            if($file->isDir){
                update_tree($file);
            }
        }
    } 
}

/**
 * returns the statistics of the files by type (extension) 
 * @param array $files_list indexed array of files
 * @return array
 */
function get_files_stats($files_list) {
    $stats = array();
    
    foreach ($files_list as $file) {
        
        // skip directories 
        if($file->isDir)
            continue ;
        
        $lower_case_extension = strtolower($file->extension); // to avoid differentiate between .txt and .TXT for example
        
        if(!isset($stats[$lower_case_extension])){
            $obj = new stdClass();
            $obj->extension = $file->extension ;
            $obj->count = 0 ;
            $obj->size = 0 ;
            $obj->sizeOnDisk = 0 ;
            $stats[$lower_case_extension]= $obj;
        }
        
        $stats[$lower_case_extension]->count++ ;
        $stats[$lower_case_extension]->size += $file->size ;
        $stats[$lower_case_extension]->sizeOnDisk += $file->sizeOnDisk ;
    }
    
    // sort stats by total size
    $sorted_stats = array_values($stats);
    usort($sorted_stats, "compare_files_size" );
    
    return $sorted_stats;
}




// -------------------     actions    -------------------

/**
 *  Page index (login form)
 */
function action_index(){
    if(has_permission())
        redirect_to_action('select');
    else
        display_index();
}

/**
 *  Login action
 */
function action_login(){
    global $password;
    
    // if POST
    if(is_post()){
        // if the password is correct
        if(isset($_POST['password']) && $_POST['password'] === $password){
            $_SESSION['wds_access'] = "1" ;
            redirect_to_action('select');
        }else{
            add_error_message("The password is incorrect. try again.");
            redirect_to_action('index');
        }
    }else{
        // if GET    
        redirect_to_action('index');
    }
}

/**
 *  Logout action
 */
function action_logout(){
    unset($_SESSION['wds_access']);
    redirect_to_action('index');
}

/**
 *  Page that contains the form to select the directory to scan
 */
function action_select(){
    check_permissions();
    display_select();
}

/**
 *  Action to scan a directory or display the result
 */
function action_scan(){
    check_permissions();
    
    // if POST
    if(is_post()){
        $path = $_POST['path'];
        if(@file_exists($path) && is_dir($path)){
            session_write_close(); // close the session to not block the other pages
			@set_time_limit(1800);  // 30 minutes timeout

            $tree = scan_folder($path);
            $files = tree_to_array($tree);
            $stats = get_files_stats($files);
            
            $data = array('files' => $files, 'stats'=> $stats);
            
            display_scan($data);
        }else{
            add_error_message('This location does not exist or is not a directory or You don\'t have the permissions ! "'.htmlspecialchars($path).'"');
            redirect_to_action('select');
        }
      
  
   
    }else{
        // if GET
        redirect_to_action('select');
    }
}


// -------------------  display pages -------------------
/**
 * Display a page (contains the layout of all pages)
 * @param string $page_title 
 * @param string $page_conten The content displayed in the page
 */
function display($page_title, $page_content){
    $error_messages = get_error_messages();
?><!doctype html>
<html lang="en">
	<head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.0/dist/css/bootstrap.min.css" rel="stylesheet" >
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.5.0/font/bootstrap-icons.css">
        <?php if(isset($_GET['action']) && $_GET['action']=='scan') { ?>
        <style>
        
            .treegrid.table-hover > tbody > tr:hover .progress {
            	background-color: white; 
            }
            
            .treegrid-expander {
                color: #ccc ;
                cursor: pointer;
                margin-right : 6px;
                margin-left : -24px;
            }
            .treegrid tbody tr {
                display: none;
            }
            .treegrid tbody tr.expanded {
                display: table-row;
            }
            
            .treegrid tbody tr td , #stats-table tbody tr td{
                padding-top: 0.15rem;
                padding-bottom: 0.15rem;
            }
        
            .treegrid  .progress{
                margin-top : 2px;
            }
        
        </style>
        <?php } ?>
    	
        <title><?php echo $page_title?></title>
  </head>
  <body>
  

	<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-2">
      <div class="container-fluid">
        <a class="navbar-brand" href="https://github.com/yassine-0x/WebDirStat">WebDirStat &nbsp;<sub>v<?php echo VERSION ?></sub>
        	<i class="bi bi-github ms-2"></i>      	
        </a> 
        
        
        <?php if(isset($_GET['action']) && $_GET['action']=='scan'){ ?>
        <span class="navbar-text text-light small">
          Path : <?php echo htmlspecialchars($_POST['path']) ?>
        </span>
        
        <a href="?action=select" class="btn btn-sm btn-outline-primary" ><i class="bi bi-search"></i> Scan another location</a>
     	<?php } ?>
     	
      	<?php if(has_permission()){ ?>
        <span class="navbar-text">
             <a href="?action=logout" class="btn btn-sm btn-danger" >Logout</a> 
        </span>
        <?php } ?>
        
      </div>
    </nav>
 
    <main class="container-fluid">
    	
    	<?php if(!empty($error_messages)){ ?>
  		<div class="alert alert-danger alert-dismissible">
           <?php foreach ($error_messages as $message) { ?>
                <div><?php echo $message ?></div>
           <?php } ?>
           <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
         </div>
  		<?php } ?>
  	
    	<?php echo $page_content ?>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.0/dist/js/bootstrap.bundle.min.js" ></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    
    <?php if(isset($_GET['action']) && $_GET['action']=='scan') { ?>
    
    <script type="text/javascript" >
    
 		// open or close a directory
        $(".treegrid-expander").click(function() { 
            let expander = $(this);
            let tr = $(this).parent().parent();

            let id = tr.attr('id').replace('treegrid-','')
            let is_open = expander.hasClass("bi-caret-down-fill");

            if(is_open){
                // change the icon
                expander.removeClass("bi-caret-down-fill").addClass("bi-caret-right-fill");
                $('tr.treegrid-collapse-'+id).hide();
            }else{
                // change the icon
                expander.removeClass("bi-caret-right-fill").addClass("bi-caret-down-fill");
                $('tr.treegrid-parent-'+id+" i.bi-caret-down-fill").removeClass("bi-caret-down-fill").addClass("bi-caret-right-fill");
                $('tr.treegrid-parent-'+id).show();
            }
        });
    </script>
   
     <?php } ?>
     
  </body>
</html>
<?php
    // remove error messages from the session
    clear_messages();
}

 
/**
 * Display the html code of the page index (login form)
 */
function display_index(){
    global $password  ;
    ob_start();
?> 
  <div class="px-4 py-5 my-5 text-center">
    <h1 class="display-5 fw-bold">WebDirStat</h1>
   
    <div class="col-lg-8 mx-auto">
   
    <?php  
        // if the password defined in the beginning of this file is empty, show an error
        if(empty($password)){ ?>
         <div class="alert alert-danger">
          	No password has been set in the file "<strong><?php echo pathinfo(__FILE__, PATHINFO_BASENAME) ?></strong>", <br /> 
          	please change the value of $password with a strong password. 
         </div>
    <?php } ?>
    
      <p class="lead mb-4"></p>
      
      <div class="  d-flex justify-content-center">
      	<div class="col-12 col-md-6">
      	    <form action="?action=login" method="post" >
                <div class="form-floating">
                  <input name="password" type="password" class="form-control" id="floatingInput" placeholder="password">
                  <label for="floatingInput">Password</label>
                </div>
                <button class="w-100 btn btn-lg btn-primary" type="submit">Go</button>            
          </form>
      	</div>
      </div>
 
    </div>
  </div>
<?php
    $page_content = ob_get_clean();	
    display("WebDirStat", $page_content );
}

/**
 * Display the html code of the page select a directory
 */
function display_select(){
    ob_start();
    ?>
  <div class="px-4 py-5 my-5 text-center">
    <h1 class="display-5 fw-bold">Select the folder</h1>
   
    <div class="col-lg-6 mx-auto">
  
      <p class="lead mb-4">Please enter the path of the folder to scan</p>
      
      <div class="  d-flex justify-content-center">
      	<div class="col-12 col-md-8 ">
      	    <form action="?action=scan" method="post" >
                <div class="form-floating">
                  <input name="path" value="<?php echo dirname(__FILE__) ?>" type="text" class="form-control" id="floatingInput" placeholder="path" >
                  <label for="floatingInput">path</label>
                </div>
                <button class="w-100 btn btn-lg btn-primary" type="submit">Scan</button>            
          </form>
          
          
      	</div>
      </div>
 
    </div>
  </div>
<?php
    $page_content = ob_get_clean();	
    display("WebDirStat - Select a folder", $page_content);
}


/**
 * Display the html code of the page result of a scan
 * @param array $data params to use in the page
 */
function display_scan($data){
    $files = $data['files'];
    $stats = $data['stats'];
    ob_start();
    ?>
   
    <table id="files-treegrid" class="treegrid table table-hover table-sm small text-end mb-4" >
    	<thead class="table-light">
            <tr>
              <th class="text-start" >Name</th>
              <th>Subtree Percent</th>
              <th>Percent </th>
              <th>Size</th>
              
              <?php if(!is_windows_os()){  // hide this column because stat($path)['blocks'] doesn't work in windows ?>
              <th>SizeOnDisk</th>
              <?php } ?>
              
              <th>Last Change</th>
              
              <th>Items</th>
              <th>Files</th>
              <th>Subdirs</th>
              
            </tr>
          </thead>
      
      <tbody>
      
        <?php 
        
        $progress_bg_colors = array('bg-info','bg-primary', 'bg-success', 'bg-warning', 'bg-danger', 'bg-secondary') ;

        foreach ($files as $file) { ?>
        
        	<tr id="treegrid-<?php echo $file->id ?>" class="<?php              
                // echo 'depth-'.$file->depth.' ';
                if ($file->parent){
                    echo "treegrid-parent-".$file->parent->id." " ;
                    foreach ($file->parentsIds as $parent_id) {
                         echo "treegrid-collapse-".$parent_id." " ;
                    }    
                }
                
                if($file->depth == 0 || $file->depth == 1){
                    echo 'expanded '; 
                }
                
                if($file->error){
                    echo "table-danger" ;
                }
              ?>">

        		<td class="text-start" style="padding-left:<?php echo 28 + $file->depth*21 ?>px">
                    <?php if($file->isDir && $file->itemsCount > 0){ ?>
                    <i class="treegrid-expander <?php echo ($file->id == 0) ? 'bi-caret-down-fill' : 'bi-caret-right-fill' ?>" ></i>
                    <?php } ?>
        			<i class="<?php echo ($file->isDir) ?'bi-folder-fill text-warning' : 'bi-file-text-fill text-muted' ?> "></i> 
        			<?php echo $file->name ?>
  
        		</td>
        		
        		<td>
            		<div class="progress" <?php echo ($file->depth > 1) ? 'style="margin-left:'.(($file->depth-1)*10).'px"' : '' ?> >
                      <div class="progress-bar <?php echo ($file->depth > 0) ? $progress_bg_colors[($file->depth-1) % sizeof($progress_bg_colors)] : 'bg-info' ?> " 
                        role="progressbar" style="width: <?php echo ceil($file->percent) ?>%" aria-valuenow="<?php echo ceil($file->percent) ?>" 
                        aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
 
        		</td>
        		
        		<td><?php echo round($file->percent, 2)  ?> %</td>
                <td><?php echo format_file_size($file->size) ?></td>
                
                <?php if(!is_windows_os()){  // hide this column because stat($path)['blocks'] doesn't work in windows ?>
                <td><?php echo format_file_size($file->sizeOnDisk) ?></td>
                <?php } ?>
                
                <td><?php echo date('Y-m-d H:i', $file->lastModificationTime) ?></td>
                
        		<td><?php if($file->isDir) echo $file->itemsCount ?></td>
        		<td><?php if($file->isDir) echo $file->filesCount ?></td>
        		<td><?php if($file->isDir) echo $file->subdirsCount ?></td>
        	</tr>
        
        <?php } ?>
      </tbody>
    </table>

    
    <!-- Statistics part -->

    <h3>Statistics </h3>
    
    <div class="row">
    	<div class="col-12  <?php echo (is_windows_os()) ? 'col-md-6' : 'col-md-7' ?>">
        	
        	<table  id="stats-table" class="table table-hover table-striped table-sm small text-end" >
            	<thead class="table-light">
                    <tr>
                      <th class="text-start" >Type</th>
                      <th>Size</th>
                      
                      <?php if(!is_windows_os()){  // hide this column because stat($path)['blocks'] doesn't work in windows ?>
                      <th>Size On Disk</th>
                      <?php } ?>
                      
                      <th>Files</th>
                    </tr>
                  </thead>
                  
         		<tbody>
              
                <?php foreach ($stats as $extension_data) { ?>               
                	<tr>
                 		<td class=" <?php echo ($extension_data->extension == "") ? 'text-center text-muted':  'text-start' ?>" ><?php 
                 		if($extension_data->extension == ""){
                 		         echo 'No Extension' ;
                 		     }else{
                 		         echo strtoupper($extension_data->extension) ;
                 		     }
                 		?></td>
                        <td><?php echo format_file_size($extension_data->size) ?></td>
                        
                        <?php if(!is_windows_os()){  // hide this column because stat($path)['blocks'] doesn't work in windows ?>
                        <td><?php echo format_file_size($extension_data->sizeOnDisk) ?></td>
                        <?php } ?>
                        
                		<td><?php echo $extension_data->count ?></td>              	 
                	</tr>              
                <?php } ?>      
                  
                  </tbody>
          </table>
      
    	</div>
    	 
    </div>
  
<?php
    $page_content = ob_get_clean();	
    display("WebDirStat - Scan result", $page_content);
}

 