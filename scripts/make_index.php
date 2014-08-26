<?php
// Called from ./make_index.sh.
// Usage: php make_index.php <root-dir> <build-number> <directory>
//    e.g.: php make_index.php /mnt/kaldi-asr-data 6 trunk/egs/wsj/s5
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
if (count($argv) != 4) {   // note: first argument is make_index.php
   syslog(LOG_ERR, "make_index.php called with wrong number of arguments: " .
         implode(" ", $argv));
  exit(1);
}
$root = $argv[1];
$build = $argv[2];
$directory = $argv[3];

if ($directory == '') {
  $slash_directory = '';
} else {
  $slash_directory = '/'.$directory;
}

$metadata = "$root/submitted/$build/metadata";
$srcdir = "$root/build/$build$slash_directory";
$destdir = "$root/build_index/$build.temp$slash_directory";

$metadata_array = file($metadata); // returns file as array, or false on error.
if ($metadata_array == false || count($metadata_array) < 3) {
  syslog(LOG_ERR, "make_index.php: file $metadata is too small or couldn't be opened.");
  exit(1);
}
foreach ($metadata_array as $line) {
  if (preg_match('/^([a-zA-Z0-9_]+)=(.+)/', $line, $matches) != 1 || count($matches) != 3) {
    # we'll allow empty lines in metadata file.
    if (preg_match('/^\s*$/', $line) != 1) {
      syslog(LOG_ERR, "make_index.php: bad line in file $metadata: $line");
      exit(1);
    }
  }
  $var_name = $matches[1];
  $var_value = $matches[2];
  $$var_name = $var_value;
}
foreach ( array('branch', 'name', 'root', 'revision', 'time', 'note') as $var_name) {
  if (!isset($$var_name)) {
    syslog(LOG_ERR, "make_index.php: variable $var_name not set in file $metadata");
    exit(1);
  }
}
date_default_timezone_set('US/Eastern');
$date = date('d M Y', $time); // Format the time of creation as a date like 30 Mar 2014

function human_readable_size($bytes) {
  $sz = ' KMGTP'; // for bytes, just print out the number.
  $factor = floor((strlen($bytes) - 1) / 3);
  $n = $bytes / pow(1024, $factor);
  $decimals = ($n < 10.0 && $factor != 0 ? 1 : 0);
  return sprintf("%.{$decimals}f", $n) . @$sz[$factor];
}
$max_downloadable_size_bytes = 1E+10; // 10G is max directory 
                                      // size for which we show the download link.
$max_link_dest_length = 40;

$file_contents = file("$destdir/size_kb"); // read the file $destdir/size_kb
if ($file_contents === false || count($file_contents) != 1
   || ! preg_match('/^\d+$/', $file_contents[0])) {
  syslog(LOG_ERR, "make_index.php: error getting size of data from $destdir/size_kb");
}
$this_dir_size_kb = $file_contents[0];
$this_dir_size_human = human_readable_size(1024 * $this_dir_size_kb);
// note: although the file is in $root/build_index/$build/<directory>,
// the URL says just 'build', not 'build_index': we do it with mod_rewrite.
$build_url = "/downloads/build/$build$slash_directory/";
$all_url = "/downloads/all$slash_directory/";

$subdir_name_to_size_kb = array(); // Map from name of subdirectories of this
                                   // directory to size in kilobytes.
$file_name_to_size_bytes = array(); // Map from name of file in this directory to
                                    // size in bytes.
$link_name_to_dest = array();  // Maps from soft-link name to soft-link destination.


if (!($handle = opendir($srcdir))) {
   syslog(LOG_ERR, "make_index.php: could not open source directory $srcdir for reading.");
   exit(1);
}
while (($file = readdir($handle)) !== false) { 
  if ($file == '.' || $file == '..') { 
    continue; 
  } elseif (is_link($srcdir."/".$file)) {
    $dest = readlink($srcdir."/".$file);
    if ($dest === false) {
      syslog(LOG_ERR, "make_index.php: error getting text of soft link $srcdir/$file");
      exit(1);
    }
    $link_name_to_dest[$file] = $dest;
  } elseif (is_dir($srcdir."/".$file)) {
    // read the file $destdir/$file/size_kb to get subdirectory total size
    // This is in a separate directory tree, in $root/build_index/ instead of
    // $root/build/
    $file_contents = file("$destdir/$file/size_kb");
    if ($file_contents === false || count($file_contents) != 1
       || ! preg_match('/^\d+$/', $file_contents[0])) {
      syslog(LOG_ERR, "make_index.php: error getting size of data from sub-directory $destdir/$file/size_kb");
      exit(1);
    }
    $subdir_name_to_size_kb[$file] = $file_contents[0];
  } elseif (is_file($srcdir."/".$file)) {
    $bytes = filesize($srcdir."/".$file);
    if ($bytes === false) {
      syslog(LOG_ERR, "make_index.php: error getting size of file $srcdir/$file");
      exit(1);
    }
    $file_name_to_size_bytes[$file] = $bytes;
  } else {
    syslog(LOG_ERR, "Directory entry $srcdir/$file is neither a directory, nor link, nor file.");
    exit(1);
  }
}
arsort($subdir_name_to_size_kb, SORT_NUMERIC); // sort high to low on value [numeric]
arsort($file_name_to_size_bytes, SORT_NUMERIC); // sort high to low on value [numeric]
ksort($link_name_to_dest, SORT_STRING); // sort low to high on key [string]


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
          <?php print "Index of /$directory/ in build $build; <a href='$all_url'> [see all builds] </a>"; ?>
        </h3>

        <div class="boxed">
  <?php print "Build <span class='content'> 2 </span> was uploaded on <span class='content'> $date </span> by <span class='content'> $name. </span> <br>\n";
        print "It was made with revision number <span class='content'>$revision</span> of Kaldi. <br>\n";
        print "<span class='content'> $note </span>\n"; ?>
         </div>

   <?php
      if (1024 * $this_dir_size_kb > $max_downloadable_size_bytes) {
        print "[This directory is too big to download]  Un-compressed size is $this_dir_size_human <br>\n";
      } else {
        print "<a href='/downloads/build/$build$slash_directory/archive.tar.gz'> [Download archive of this directory] </a> Un-compressed size is $this_dir_size_human. " .
           ($this_dir_size_kb > 1000 ? "Expect a short delay." : "") . " <br>\n";
      }
    ?>

       <table style="margin-top:0.2em">
        <tr>  <th>Name</th>    <th>Size</th>    </tr>
<?php
   foreach ($subdir_name_to_size_kb as $subdir => $subdir_size_kb) {
     // Note regarding $href: the actual index is located in build_index, the
     // external url just says 'build' and we redirect it in the apache config.
     $subdir_size_human = human_readable_size(1024 * $subdir_size_kb);
     $href = "/downloads/build/$build$slash_directory/$subdir/index.html"; 
     print "<tr> <td><a href='$href'> $subdir/ </td> <td> $subdir_size_human </td> </tr>\n";
   }
   foreach ($file_name_to_size_bytes as $file => $file_size_bytes) {
     $file_size_human = human_readable_size($file_size_bytes);
     $href = "/downloads/build/$build$slash_directory/$file";
     print "<tr> <td><a href='$href'> $file </td> <td> $file_size_human </td> </tr>\n";
   }
   foreach ($link_name_to_dest as $link_name => $link_dest) {
     if (strlen($link_dest) > $max_link_dest_length) {
       // We can consider using javascript to make the whole thing showable, or
       // maybe putting a href in here, if users ask for this feature.
       $link_dest = substr($link_dest, 0, $max_link_dest_length - 2) . '..';     }
     print "<tr> <td> $link_name &rarr; $link_dest </td> <td> - </td> </tr>\n";
   } ?>
         </table>


       </div>  <!-- main content.  -->
      </div> 
    </div>
  </body>      
</html>

