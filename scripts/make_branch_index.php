<?php
// Called from ./make_index.sh.
// Usage: php make_branch_index.php <root-dir> <branch-name> <output-dir> <input-dirs>
//    e.g.: php make_index.php trunk /mnt/kaldi-asr-data/tree.temp/trunk/egs/wsj /mnt/kaldi-asr-data/build/{1,15,17}/trunk/egs/wsj"
?>
<!DOCTYPE html>
<html>
  <head>
    <meta name="description" content="Kaldi ASR"/>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/kaldi_ico.png"/>
    <link rel="stylesheet" type="txt/css" href="/indexes.css"/> 
    <title>Kaldi ASR</title>

<?php
if (count($argv) < 5) {
   // note: first argument is make_branch_index.php, we need at least 4 real arguments
   syslog(LOG_ERR, "make_branch_index.php called with too few arguments: " .
         implode(" ", $argv));
  exit(1);
}
$root = $argv[1];
$branch = $argv[2];
$destdir = $argv[3];

$input_dirs = array();  // we'll index this as numeric build-number -> input-dir,
                        // and sort numerically by build number.
for ($n = 4; $n < count($argv); $n++) {
  $input_dir = $argv[$n];
  if (preg_match("|^$root/build/(\d+)|", $input_dir, $matches) != 1) {
    syslog(LOG_ERR, "make_branch_index.php: input directory $input_dir did not start with $root/build/(digits)/");
    exit(1);
  }
  $build = $matches[1];
  if (isset($input_dirs[$build])) {
    syslog(LOG_ERR, "make_branch_index.php: multiple instances of build $build on command line");
    exit(1);
  }
  $input_dirs[$build] = $input_dir;
}
// Sort the input_dirs array from smallest to largest build index, 
// numerically.
ksort($input_dirs, SORT_NUMERIC);


date_default_timezone_set('US/Eastern');
function human_readable_size($bytes) {
  $sz = ' KMGTP'; // for bytes, just print out the number.
  $factor = floor((strlen($bytes) - 1) / 3);
  $n = $bytes / pow(1024, $factor);
  $decimals = ($n < 10.0 && $factor != 0 ? 1 : 0);
  return sprintf("%.{$decimals}f", $n) . @$sz[$factor];
}


// we'll index the associative array "all_metadata" as
// <build-index>.<variable-name>, e.g. "6.revision" = 1421
$all_metdata = array();  

foreach ($input_dirs as $build_index => $input_dir) {
  $metadata = "$root/submitted/$build/metadata";
  $metadata_array = file($metadata); // returns file as array, or false on error.
  if ($metadata_array == false || count($metadata_array) < 3) {
    syslog(LOG_ERR, "make_branch_index.php: file $metadata is too small or couldn't be opened.");
    exit(1);
  }
  foreach ($metadata_array as $line) {
    if (preg_match('/^([a-zA-Z0-9_]+)=(.+)/', $line, $matches) != 1 || count($matches) != 3) {
      # we'll allow empty lines in metadata file.
      if (preg_match('/^\s*$/', $line) != 1) {
        syslog(LOG_ERR, "make_branch_index.php: bad line in file $metadata: $line");
        exit(1);
      }
    }
    $var_name = $matches[1];
    $var_value = $matches[2];
    $all_metadata["$build_index.$var_name"] = $var_value;
  }
  foreach (array('branch', 'name', 'root', 'revision', 'time', 'note') as $var_name) {
    if (!isset($all_metadata["$build_index.$var_name"])) {
      syslog(LOG_ERR, "make_branch_index.php: variable $var_name not set in metadata file $metadata");
      exit(1);
    }
  }
  // Format the time of creation as a date like 30 Mar 2014
  $all_metadata["$build_index.date"] = date('d M Y', $all_metadata["$build_index.time"]);

  // work out the location of the index directory corresponding to this input dir.
  $old_prefix = "$root/build/";
  $old_prefix_length = strlen($old_prefix);
  if (substr($input_dir, 0, $old_prefix_length) != $old_prefix) {
    syslog(LOG_ERR, "make_branch_index.php: expected input dir $input_dir to start with $old_prefix");
    exit(1);
  }
  $unique_part = substr($input_dir, $old_prefix_length);
  $index_dir = "$root/build_index/$unique_part";
  $all_metadata["$build_index.index_dir"] = $index_dir;

  // also work out the URL for each of the versions of this directory.  Note, this
  // is located in /downloads/build, not /downloads/build_index; we map it in the 
  // apache config.
  $index_url = "/downloads/build/$unique_part";
  $all_metadata["$build_index.index_url"] = $index_url;

  // Look in the index dir for the file size_kb that tells us the size of
  // this version (inside this directory).
  $file_contents = file("$index_dir/size_kb");
  if ($file_contents === false || count($file_contents) != 1
     || ! preg_match('/^\d+$/', $file_contents[0])) {
    syslog(LOG_ERR, "make_branch_index.php: error getting size of data from $input_dir/size_kb");
    exit(1);
  }
  $all_metadata["$build_index.size_kb"] = $file_contents[0];
  $all_metadata["$build_index.size_human_readable"] = human_readable_size(1024 * $file_contents[0]);
}

// Now figure out the URL $my_url by which we can refer to the index.html that we're
// creating in this script.
$prefix = "$root/tree.temp/";
$prefix_length = strlen($prefix);
if (substr($destdir, 0, $prefix_length) != $prefix) {
  syslog(LOG_ERR, "make_branch_index.php: expected dest dir (third argument) to begin with $prefix, got $destdir");
  exit(1);
}
$my_url = "/downloads/tree/" . substr($destdir, $prefix_length);

// set $my_directory to the part of the pathname of $destdir
// that appears after the branch name.
$prefix = "$root/tree.temp/$branch";
$prefix_length = strlen($prefix);
if (substr($destdir, 0, $prefix_length) != $prefix) {
  syslog(LOG_ERR, "make_branch_index.php: expected dest dir (third argument) to begin with $prefix, got $destdir");
  exit(1);
}
$my_directory = substr($destdir, $prefix_length);

// set $all_url, to something like /downloads/all/egs/wsj/s5; this will take the user
// to an index.html file that points to available branches for this location.
$all_url = "/downloads/all" . $my_directory;


// get a list of subdirectories of this directory.
// $subdirs will be indexed by all strings $subdir, such that
// $input_dir/$subdir exists and is a directory for at least
// one member $input_dir of $input_dirs.
// We'll use this list to make links in our
// output html that go to subdirectories of $my_url.  
// note: the entries will appear as the key of $subdirs,
// note the entry.  The entry will always be 1.
$subdirs = array();

foreach ($input_dirs as $input_dir) {
  $entries = scandir($input_dir); // returns array of entries.  
  foreach ($entries as $entry) {
    $path = "$input_dir/$entry";
    if (is_dir($path) && !is_link($path) && $entry != "." && $entry != "..") {
      $subdirs[$entry] = 1;
    }
  }
}

ksort($subdirs, SORT_STRING); // sort low to high on key [string]


?>

    
  </head>
  <body>
    <div class="container">
      <div id="centeredContainer">
        <div id="headerBar">
          <div id="headerLeft">  <image id="logoImage" src="/kaldi_text_and_logo.png"> </div>
          <div id="headerRight"> <image id="logoImage" src="/kaldi_logo.png">  </div>
        </div>
        <hr>

        <div id="mainContent">

        <h3>
         <?php print "Index of $my_directory/ in branch $branch; <a href='$all_url'> [see all branches] </a>"; ?>
        </h3>


        <h3> Builds available for this directory: </h3>

       <table style="margin-top:0.2em">
        <tr>  <th>Build number</th>   <th>Uploader</th>  <th>Date</th>  <th>Kaldi revision</th>   <th>Size</th> <th>Note</th> </tr>
  <?php
       foreach ($input_dirs as $build_index => $input_dir) {
          $index_url = $all_metadata["$build_index.index_url"];
          $uploader_name = $all_metadata["$build_index.name"];
          $date = $all_metadata["$build_index.date"];
          $revision = $all_metadata["$build_index.revision"];
          $size = $all_metadata["$build_index.size_human_readable"];
          $note = $all_metadata["$build_index.note"];
 
          print "<tr> <td> <a href='$index_url'> $build_index </a> ";
          print "<td> $uploader_name </td> ";
          print "<td> $date </td> ";
          print "<td> r$revision </td> ";
          print "<td> $size </td> ";
          print "<td> $note </td> </tr>\n";
       }  
   ?>
    </table>    

     

      <h3> Subdirectories: </h3>
   <?php
      foreach ($subdirs as $subdir => $foo) {
         print "           <a href='$my_url/$subdir'> $subdir/ </a> <br>\n";
      }
      if ($my_directory != "") {
        print "           <p/>\n";
        print "           <a href='$my_url/..'> [parent directory] </a> <br>\n";
      }
   ?>

       </div>  <!-- main content.  -->
      </div> 
    </div>
  </body>      
</html>

