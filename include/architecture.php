<div class="magick-header">
<p class="text-center"><a href="#cache">The Pixel Cache</a> • <a href="#stream">Streaming Pixels</a> • <a href="#properties">Image Properties and Profiles</a> • <a href="#tera-pixel">Large Image Support</a> • <a href="#threads">Threads of Execution</a> • <a href="#distributed">Heterogeneous Distributed Processing</a> • <a href="#coders">Custom Image Coders</a> • <a href="#filters">Custom Image Filters</a></p>

<p class="lead magick-description">The citizens of Oz were quite content with their benefactor, the all-powerful Wizard.  They accepted his wisdom and benevolence without ever questioning the who, why, and where of his power.  Like the citizens of Oz, if you feel comfortable that ImageMagick can help you convert, edit, or compose your images without knowing what goes on behind the curtain, feel free to skip this section.  However, if you want to know more about the software and algorithms behind ImageMagick, read on.  To fully benefit from this discussion, you should be comfortable with image nomenclature and be familiar with computer programming.</p>

<h2 class="magick-post-title"><a class="anchor" id="overview"></a>Architecture Overview</h2>

<p>An image typically consists of a rectangular region of pixels and metadata.  To convert, edit, or compose an image in an efficient manner, we need convenient access to any pixel anywhere within the region (and sometimes outside the region).  And in the case of an image sequence, we need access to any pixel of any region of any image in the sequence.  However, there are hundreds of image formats such JPEG, TIFF, PNG, GIF, etc., that makes it difficult to access pixels on demand.  Within these formats we find differences in:</p>

<ul>
  <li>colorspace (e.g sRGB, linear RGB, linear GRAY, CMYK, YUV, Lab, etc.)</li>
  <li>bit depth (.e.g 1, 4, 8, 12, 16, etc.)</li>
  <li>storage format (e.g. unsigned, signed, float, double, etc.)</li>
  <li>compression (e.g. uncompressed, RLE, Zip, BZip, etc.)</li>
  <li>orientation (i.e. top-to-bottom, right-to-left, etc.),</li>
  <li>layout (.e.g. raw, interspersed with opcodes, etc.)</li>
</ul>

<p>In addition, some image pixels may require attenuation, some formats permit more than one frame, and some formats contain vector graphics that must first be rasterized (converted from vector to pixels).</p>

<p>An efficient implementation of an image processing algorithm may require we get or set:</p>

<ul>
  <li>one pixel a time (e.g. pixel at location 10,3)</li>
  <li>a single scanline (e.g. all pixels from row 4)</li>
  <li>a few scanlines at once (e.g. pixel rows 4-7)</li>
  <li>a single column or columns of pixels (e.g. all pixels from column 11)</li>
  <li>an arbitrary region of pixels from the image (e.g. pixels defined at 10,7 to 10,19)</li>
  <li>a pixel in random order (e.g. pixel at 14,15 and 640,480)</li>
  <li>pixels from two different images (e.g. pixel at 5,1 from image 1 and pixel at 5,1 from image 2)</li>
  <li>pixels outside the boundaries of the image (e.g. pixel at -1,-3)</li>
  <li>a pixel component that is unsigned (65311) or in a floating-point representation (e.g. 0.17836)</li>
  <li>a high-dynamic range pixel that can include negative values (e.g. -0.00716) as well as values that exceed the quantum depth (e.g. 65931)</li>
  <li>one or more pixels simultaneously in different threads of execution</li>
  <li>all the pixels in memory to take advantage of speed-ups offered by executing in concert across heterogeneous platforms consisting of CPUs, GPUs, and other processors</li>
</ul>

<p>Some images include a clip mask that define which pixels are eligible to be updated.  Pixels outside the area defined by the clip mask remain untouched.</p>

<p>Given the varied image formats and image processing requirements, we implemented the ImageMagick <a href="#cache">pixel cache</a> to provide convenient sequential or parallel access to any pixel on demand anywhere inside the image region (i.e. <a href="#authentic-pixels">authentic pixels</a>)  and from any image in a sequence.  In addition, the pixel cache permits access to pixels outside the boundaries defined by the image (i.e. <a href="#virtual-pixels">virtual pixels</a>).</p>

<p>In addition to pixels, images have a plethora of <a href="#properties">image properties and profiles</a>.  Properties include the well known attributes such as width, height, depth, and colorspace.  An image may have optional properties which might include the image author, a comment, a create date, and others.  Some images also include profiles for color management, or EXIF, IPTC, 8BIM, or XMP informational profiles.  ImageMagick provides command line options and programming methods to get, set, or view image properties or profiles or apply profiles.</p>

<p>ImageMagick consists of nearly a half million lines of C code and optionally depends on several million lines of code in dependent libraries (e.g. JPEG, PNG, TIFF libraries).  Given that, one might expect a huge architecture document.  However, a great majority of image processing is simply accessing pixels and its metadata and our simple, elegant, and efficient implementation makes this easy for the ImageMagick developer.  We discuss the implementation of the pixel cache and getting and setting image properties and profiles in the next few sections. Next, we discuss using ImageMagick within a <a href="#threads">thread</a> of execution.  In the final sections, we discuss <a href="#coders">image coders</a> to read or write a particular image format followed by a few words on creating a <a href="#filters">filter</a> to access or update pixels based on your custom requirements.</p>

<h2 class="magick-post-title"><a class="anchor" id="cache"></a>The Pixel Cache</h2>

<p>The ImageMagick pixel cache is a repository for image pixels with up to 32 channels.  The channels are stored contiguously at the depth specified when ImageMagick was built.  The channel depths are 8 bits-per-pixel component for the Q8 version of ImageMagick, 16 bits-per-pixel component for the Q16 version, and 32 bits-per-pixel component for the Q32 version.  By default pixel components are 32-bit floating-bit <a href="<?php echo $_SESSION['RelativePath']?>/../script/high-dynamic-range.php">high dynamic-range</a> quantities. The channels can hold any value but typically contain red, green, blue, and alpha intensities or cyan, magenta, yellow, alpha intensities.  A channel might contain the colormap indexes for colormapped images or the black channel for CMYK images.  The pixel cache storage may be heap memory, disk-backed memory mapped, or on disk.  The pixel cache is reference-counted.  Only the cache properties are copied when the cache is cloned.  The cache pixels are subsequently copied only when you signal your intention to update any of the pixels.</p>

<h3>Create the Pixel Cache</h3>

<p>The pixel cache is associated with an image when it is created and it is initialized when you try to get or put pixels.  Here are three common methods to associate a pixel cache with an image:</p>

<dl>
<dt class="col-md-8">Create an image canvas initialized to the background color:</dt><br/>
<dd class="col-md-8"><pre class="highlight"><code>image=AllocateImage(image_info);
if (SetImageExtent(image,640,480) == MagickFalse)
  { /* an exception was thrown */ }
(void) QueryMagickColor("red",&amp;image-&gt;background_color,&amp;image-&gt;exception);
SetImageBackgroundColor(image);
</code></pre></dd>

<dt class="col-md-8">Create an image from a JPEG image on disk:</dt><br/>
<dd class="col-md-8"><pre class="highlight"><code>(void) strcpy(image_info-&gt;filename,"image.jpg"):
image=ReadImage(image_info,exception);
if (image == (Image *) NULL)
  { /* an exception was thrown */ }
</code></pre></dd>
<dt class="col-md-8">Create an image from a memory based image:</dt><br/>
<dd class="col-md-8"><pre class="highlight"><code>image=BlobToImage(blob_info,blob,extent,exception);
if (image == (Image *) NULL)
  { /* an exception was thrown */ }
</code></pre></dd>
</dl>

<p>In our discussion of the pixel cache, we use the <a href="<?php echo $_SESSION['RelativePath']?>/../script/magick-core.php">MagickCore API</a> to illustrate our points, however, the principles are the same for other program interfaces to ImageMagick.</p>

<p>When the pixel cache is initialized, pixels are scaled from whatever bit depth they originated from to that required by the pixel cache.  For example, a 1-channel 1-bit monochrome PBM image is scaled to 8-bit gray image, if you are using the Q8 version of ImageMagick, and 16-bit RGBA for the Q16 version.  You can determine which version you have with the <?php option("version"); ?> option: </p>

<?php crt("identify -version", "<br/>",
"Version: ImageMagick " .MagickLibVersionText . MagickLibSubversion . " " . MagickReleaseDate . " Q16 https://www.imagemagick.org"); ?>

<p>As you can see, the convenience of the pixel cache sometimes comes with a trade-off in storage (e.g. storing a 1-bit monochrome image as 16-bit is wasteful) and speed (i.e. storing the entire image in memory is generally slower than accessing one scanline of pixels at a time).  In most cases, the benefits of the pixel cache typically outweigh any disadvantages.</p>

<h3><a class="anchor" id="authentic-pixels"></a>Access the Pixel Cache</h3>

<p>Once the pixel cache is associated with an image, you typically want to get, update, or put pixels into it.  We refer to pixels inside the image region as <a href="#authentic-pixels">authentic pixels</a> and outside the region as <a href="#virtual-pixels">virtual pixels</a>.  Use these methods to access the pixels in the cache:</p>
<ul>
  <li><a href="<?php echo $_SESSION['RelativePath']?>/../api/cache.php#GetVirtualPixels">GetVirtualPixels()</a>: gets pixels that you do not intend to modify or pixels that lie outside the image region (e.g. pixel @ -1,-3)</li>
  <li><a href="<?php echo $_SESSION['RelativePath']?>/../api/cache.php#GetAuthenticPixels">GetAuthenticPixels()</a>: gets pixels that you intend to modify</li>
  <li><a href="<?php echo $_SESSION['RelativePath']?>/../api/cache.php#QueueAuthenticPixels">QueueAuthenticPixels()</a>: queue pixels that you intend to set</li>
  <li><a href="<?php echo $_SESSION['RelativePath']?>/../api/cache.php#SyncAuthenticPixels">SyncAuthenticPixels()</a>: update the pixel cache with any modified pixels</li>
</ul>

<p>Here is a typical <a href="<?php echo $_SESSION['RelativePath']?>/../script/magick-core.php">MagickCore</a> code snippet for manipulating pixels in the pixel cache.  In our example, we copy pixels from the input image to the output image and decrease the intensity by 10%:</p>

<pre class="pre-scrollable"><code>const Quantum
  *p;

Quantum
  *q;

ssize_t
  x,
  y;

destination=CloneImage(source,source->columns,source->rows,MagickTrue,
  exception);
if (destination == (Image *) NULL)
  { /* an exception was thrown */ }
for (y=0; y &lt; (ssize_t) source-&gt;rows; y++)
{
  p=GetVirtualPixels(source,0,y,source-&gt;columns,1,exception);
  q=GetAuthenticPixels(destination,0,y,destination-&gt;columns,1,exception);
  if ((p == (const Quantum *) NULL) || (q == (Quantum *) NULL)
    break;
  for (x=0; x &lt; (ssize_t) source-&gt;columns; x++)
  {
    SetPixelRed(image,90*p-&gt;red/100,q);
    SetPixelGreen(image,90*p-&gt;green/100,q);
    SetPixelBlue(image,90*p-&gt;blue/100,q);
    SetPixelAlpha(image,90*p-&gt;opacity/100,q);
    p+=GetPixelChannels(source);
    q+=GetPixelChannels(destination);
  }
  if (SyncAuthenticPixels(destination,exception) == MagickFalse)
    break;
}
if (y &lt; (ssize_t) source-&gt;rows)
  { /* an exception was thrown */ }
</code></pre>

<p>When we first create the destination image by cloning the source image, the pixel cache pixels are not copied.  They are only copied when you signal your intentions to modify or set the pixel cache by calling <a href="<?php echo $_SESSION['RelativePath']?>/../api/cache.php#GetAuthenticPixels">GetAuthenticPixels()</a> or <a href="<?php echo $_SESSION['RelativePath']?>/../api/cache.php#QueueAuthenticPixels">QueueAuthenticPixels()</a>. Use <a href="<?php echo $_SESSION['RelativePath']?>/../api/cache.php#QueueAuthenticPixels">QueueAuthenticPixels()</a> if you want to set new pixel values rather than update existing ones.  You could use GetAuthenticPixels() to set pixel values but it is slightly more efficient to use QueueAuthenticPixels() instead. Finally, use <a href="<?php echo $_SESSION['RelativePath']?>/../api/cache.php#SyncAuthenticPixels">SyncAuthenticPixels()</a> to ensure any updated pixels are pushed to the pixel cache.</p>

<p>You can associate arbitrary content with each pixel, called <em>meta</em> content.  Use  <a href="<?php echo $_SESSION['RelativePath']?>/../api/cache.php#GetVirtualMetacontent">GetVirtualMetacontent()</a> (to read the content) or <a href="<?php echo $_SESSION['RelativePath']?>/../api/cache.php#GetAuthenticMetacontent">GetAuthenticMetacontent()</a> (to update the content) to gain access to this content.  For example, to print the metacontent, use:</p>

<pre class="highlight"><code>const void
  *metacontent;

for (y=0; y &lt; (ssize_t) source-&gt;rows; y++)
{
  p=GetVirtualPixels(source,0,y,source-&gt;columns,1);
  if (p == (const Quantum *) NULL)
    break;
  metacontent=GetVirtualMetacontent(source);
  /* print meta content here */
}
if (y &lt; (ssize_t) source-&gt;rows)
  /* an exception was thrown */
</code></pre>

<p>The pixel cache manager decides whether to give you direct or indirect access to the image pixels.  In some cases the pixels are staged to an intermediate buffer-- and that is why you must call SyncAuthenticPixels() to ensure this buffer is <var>pushed</var> out to the pixel cache to guarantee the corresponding pixels in the cache are updated.  For this reason we recommend that you only read or update a scanline or a few scanlines of pixels at a time.  However, you can get any rectangular region of pixels you want.  GetAuthenticPixels() requires that the region you request is within the bounds of the image area.  For a 640 by 480 image, you can get a scanline of 640 pixels at row 479 but if you ask for a scanline at row 480, an exception is returned (rows are numbered starting at 0).  GetVirtualPixels() does not have this constraint.  For example,</p>

<pre class="highlight"><code>p=GetVirtualPixels(source,-3,-3,source-&gt;columns+3,6,exception);
</code></pre>

<p>gives you the pixels you asked for without complaint, even though some are not within the confines of the image region.</p>

<h3><a class="anchor" id="virtual-pixels"></a>Virtual Pixels</h3>

<p>There are a plethora of image processing algorithms that require a neighborhood of pixels about a pixel of interest.  The algorithm typically includes a caveat concerning how to handle pixels around the image boundaries, known as edge pixels.  With virtual pixels, you do not need to concern yourself about special edge processing other than choosing  which virtual pixel method is most appropriate for your algorithm.</p>
 <p>Access to the virtual pixels are controlled by the <a href="<?php echo $_SESSION['RelativePath']?>/../api/cache.php#SetImageVirtualPixelMethod">SetImageVirtualPixelMethod()</a> method from the MagickCore API or the <?php option("virtual-pixel"); ?> option from the command line.  The methods include:</p>

<dl class="row">
<dt class="col-md-4">background</dt>
<dd class="col-md-8">the area surrounding the image is the background color</dd>
<dt class="col-md-4">black</dt>
<dd class="col-md-8">the area surrounding the image is black</dd>
<dt class="col-md-4">checker-tile</dt>
<dd class="col-md-8">alternate squares with image and background color</dd>
<dt class="col-md-4">dither</dt>
<dd class="col-md-8">non-random 32x32 dithered pattern</dd>
<dt class="col-md-4">edge</dt>
<dd class="col-md-8">extend the edge pixel toward infinity (default)</dd>
<dt class="col-md-4">gray</dt>
<dd class="col-md-8">the area surrounding the image is gray</dd>
<dt class="col-md-4">horizontal-tile</dt>
<dd class="col-md-8">horizontally tile the image, background color above/below</dd>
<dt class="col-md-4">horizontal-tile-edge</dt>
<dd class="col-md-8">horizontally tile the image and replicate the side edge pixels</dd>
<dt class="col-md-4">mirror</dt>
<dd class="col-md-8">mirror tile the image</dd>
<dt class="col-md-4">random</dt>
<dd class="col-md-8">choose a random pixel from the image</dd>
<dt class="col-md-4">tile</dt>
<dd class="col-md-8">tile the image</dd>
<dt class="col-md-4">transparent</dt>
<dd class="col-md-8">the area surrounding the image is transparent blackness</dd>
<dt class="col-md-4">vertical-tile</dt>
<dd class="col-md-8">vertically tile the image, sides are background color</dd>
<dt class="col-md-4">vertical-tile-edge</dt>
<dd class="col-md-8">vertically tile the image and replicate the side edge pixels</dd>
<dt class="col-md-4">white</dt>
<dd class="col-md-8">the area surrounding the image is white</dd>
</dl>


<h3>Cache Storage and Resource Requirements</h3>

<p>Recall that this simple and elegant design of the ImageMagick pixel cache comes at a cost in terms of storage and processing speed.  The pixel cache storage requirements scales with the area of the image and the bit depth of the pixel components.  For example, if we have a 640 by 480 image and we are using the non-HDRI Q16 version of ImageMagick, the pixel cache consumes image <var>width * height * bit-depth / 8 * channels</var> bytes or approximately 2.3 mebibytes (i.e. 640 * 480 * 2 * 4).  Not too bad, but what if your image is 25000 by 25000 pixels?  The pixel cache requires approximately 4.7 gibibytes of storage.  Ouch.  ImageMagick accounts for possible huge storage requirements by caching large images to disk rather than memory.  Typically the pixel cache is stored in memory using heap memory. If heap memory is exhausted, we create the pixel cache on disk and attempt to memory-map it. If memory-map memory is exhausted, we simply use standard disk I/O.  Disk storage is cheap but it is also very slow, upwards of 1000 times slower than memory.  We can get some speed improvements, up to 5 times, if we use memory mapping to the disk-based cache.  These decisions about storage are made <var>automagically</var> by the pixel cache manager negotiating with the operating system.  However, you can influence how the pixel cache manager allocates the pixel cache with <var>cache resource limits</var>.  The limits include:</p>

<dl class="row">
  <dt class="col-md-4">width</dt>
  <dd class="col-md-8">maximum width of an image.  Exceed this limit and an exception is thrown and processing stops.</dd>
  <dt class="col-md-4">height</dt>
  <dd class="col-md-8">maximum height of an image.  Exceed this limit and an exception is thrown and processing stops.</dd>
  <dt class="col-md-4">area</dt>
  <dd class="col-md-8">maximum area in bytes of any one image that can reside in the pixel cache memory.  If this limit is exceeded, the image is automagically cached to disk and optionally memory-mapped.</dd>
  <dt class="col-md-4">memory</dt>
  <dd class="col-md-8">maximum amount of memory in bytes to allocate for the pixel cache from the heap.</dd>
  <dt class="col-md-4">map</dt>
  <dd class="col-md-8">maximum amount of memory map in bytes to allocate for the pixel cache.</dd>
  <dt class="col-md-4">disk</dt>
  <dd class="col-md-8">maximum amount of disk space in bytes permitted for use by the pixel cache.  If this limit is exceeded, the pixel cache is not created and a fatal exception is thrown.</dd>
  <dt class="col-md-4">files</dt>
  <dd class="col-md-8">maximum number of open pixel cache files.  When this limit is exceeded, any subsequent pixels cached to disk are closed and reopened on demand. This behavior permits a large number of images to be accessed simultaneously on disk, but without a speed penalty due to repeated open/close calls.</dd>
  <dt class="col-md-4">thread</dt>
  <dd class="col-md-8">maximum number of threads that are permitted to run in parallel.</dd>
  <dt class="col-md-4">time</dt>
  <dd class="col-md-8">maximum number of seconds that the process is permitted to execute.  Exceed this limit and an exception is thrown and processing stops.</dd>
</dl>

<p>Note, these limits pertain to the ImageMagick pixel cache.  Certain algorithms within ImageMagick do not respect these limits nor does any of the external delegate libraries (e.g. JPEG, TIFF, etc.).</p>

<p>To determine the current setting of these limits, use this command:</p>
<pre class="highlight">-> identify -list resource
Resource limits:
  Width: 100MP
  Height: 100MP
  Area: 25.181GB
  Memory: 11.726GiB
  Map: 23.452GiB
  Disk: unlimited
  File: 768
  Thread: 12
  Throttle: 0
  Time: unlimited
</pre>

<p>You can set these limits either as a <a href="<?php echo $_SESSION['RelativePath']?>/../script/security-policy.php">security policy</a> (see <a href="<?php echo $_SESSION['RelativePath']?>/../source/policy.xml">policy.xml</a>), with an <a href="<?php echo $_SESSION['RelativePath']?>/../script/resources.php#environment">environment variable</a>, with the <a href="<?php echo $_SESSION['RelativePath']?>/../script/command-line-options.php#limit">-limit</a> command line option, or with the <a href="<?php echo $_SESSION['RelativePath']?>/../api/resource.php#SetMagickResourceLimit">SetMagickResourceLimit()</a> MagickCore API method. As an example, our online web interface to ImageMagick, <a href="https://www.imagemagick.org/MagickStudio/scripts/MagickStudio.cgi">ImageMagick Studio</a>, includes these policy limits to help prevent a denial-of-service:</p>
<pre class="highlight"><code>&lt;policymap>
  &lt;policy domain="resource" name="temporary-path" value="/tmp"/>
  &lt;policy domain="resource" name="memory" value="256MiB"/>
  &lt;policy domain="resource" name="map" value="512MiB"/>
  &lt;policy domain="resource" name="width" value="8KP"/>
  &lt;policy domain="resource" name="height" value="8KP"/>
  &lt;policy domain="resource" name="area" value="128MB"/>
  &lt;policy domain="resource" name="disk" value="1GiB"/>
  &lt;policy domain="resource" name="file" value="768"/>
  &lt;policy domain="resource" name="thread" value="2"/>
  &lt;policy domain="resource" name="throttle" value="0"/>
  &lt;policy domain="resource" name="time" value="120"/>
  &lt;policy domain="system" name="precision" value="6"/>
  &lt;policy domain="cache" name="shared-secret" value="replace with your secret phrase" stealth="true"/>
  &lt;policy domain="delegate" rights="none" pattern="HTTPS" />
  &lt;policy domain="path" rights="none" pattern="@*"/>  &lt;!-- indirect reads not permitted -->
&lt;/policymap>
</code></pre>
<p>Since we process multiple simultaneous sessions, we don't want any one session consuming all the available memory.With this policy, large images are cached to disk. If the image is too large and exceeds the pixel cache disk limit, the program exits. In addition, we place a time limit to prevent any run-away processing tasks. If any one image has a width or height that exceeds 8192 pixels, an exception is thrown and processing stops. As of ImageMagick 7.0.1-8 you can prevent the use of any delegate or all delegates (set the pattern to "*"). Note, prior to this release, use a domain of "coder" to prevent delegate usage (e.g. domain="coder" rights="none" pattern="HTTPS"). The policy also prevents indirect reads.  If you want to, for example, read text from a file (e.g. caption:@myCaption.txt), you'll need to remove this policy.</p>

<p>Note, the cache limits are global to each invocation of ImageMagick, meaning if you create several images, the combined resource requirements are compared to the limit to determine the pixel cache storage disposition.</p>

<p>To determine which type and how much resources are consumed by the pixel cache, add the <a href="<?php echo $_SESSION['RelativePath']?>/../script/command-line-options.php#debug">-debug cache</a> option to the command-line:</p>
<pre class="highlight">-> convert -debug cache logo: -sharpen 3x2 null:
2016-12-17T13:33:42-05:00 0:00.000 0.000u 7.0.0 Cache convert: cache.c/DestroyPixelCache/1275/Cache
  destroy 
2016-12-17T13:33:42-05:00 0:00.000 0.000u 7.0.0 Cache convert: cache.c/OpenPixelCache/3834/Cache
  open LOGO[0] (Heap Memory, 640x480x4 4.688MiB)
2016-12-17T13:33:42-05:00 0:00.010 0.000u 7.0.0 Cache convert: cache.c/OpenPixelCache/3834/Cache
  open LOGO[0] (Heap Memory, 640x480x3 3.516MiB)
2016-12-17T13:33:42-05:00 0:00.010 0.000u 7.0.0 Cache convert: cache.c/ClonePixelCachePixels/1044/Cache
  Memory => Memory
2016-12-17T13:33:42-05:00 0:00.020 0.010u 7.0.0 Cache convert: cache.c/ClonePixelCachePixels/1044/Cache
  Memory => Memory
2016-12-17T13:33:42-05:00 0:00.020 0.010u 7.0.0 Cache convert: cache.c/OpenPixelCache/3834/Cache
  open LOGO[0] (Heap Memory, 640x480x3 3.516MiB)
2016-12-17T13:33:42-05:00 0:00.050 0.100u 7.0.0 Cache convert: cache.c/DestroyPixelCache/1275/Cache
  destroy LOGO[0]
2016-12-17T13:33:42-05:00 0:00.050 0.100u 7.0.0 Cache convert: cache.c/DestroyPixelCache/1275/Cache
  destroy LOGO[0]
</pre>
<p>This command utilizes a pixel cache in memory.  The logo consumed 4.688MiB and after it was sharpened, 3.516MiB.</p>


<h3>Distributed Pixel Cache</h3>
<p>A distributed pixel cache is an extension of the traditional pixel cache available on a single host.  The distributed pixel cache may span multiple servers so that it can grow in size and transactional capacity to support very large images.  Start up the pixel cache server on one or more machines.  When you read or operate on an image and the local pixel cache resources are exhausted, ImageMagick contacts one or more of these remote pixel servers to store or retrieve pixels.  The distributed pixel cache relies on network bandwidth to marshal pixels to and from the remote server.  As such, it will likely be significantly slower than a pixel cache utilizing local storage (e.g. memory, disk, etc.).</p>
<pre class="highlight"><code>convert -distribute-cache 6668 &amp;  // start on 192.168.100.50
convert -define registry:cache:hosts=192.168.100.50:6668 myimage.jpg -sharpen 5x2 mimage.png
</code></pre>

<h3>Cache Views</h3>

<p>GetVirtualPixels(), GetAuthenticPixels(), QueueAuthenticPixels(), and SyncAuthenticPixels(), from the MagickCore API, can only deal with one pixel cache area per image at a time.  Suppose you want to access the first and last scanline from the same image at the same time?  The solution is to use a <var>cache view</var>.  A cache view permits you to access as many areas simultaneously in the pixel cache as you require.  The cache view <a href="<?php echo $_SESSION['RelativePath']?>/../api/cache-view.php">methods</a> are analogous to the previous methods except you must first open a view and close it when you are finished with it. Here is a snippet of MagickCore code that permits us to access the first and last pixel row of the image simultaneously:</p>
<pre class="pre-scrollable"><code>CacheView
  *view_1,
  *view_2;

view_1=AcquireVirtualCacheView(source,exception);
view_2=AcquireVirtualCacheView(source,exception);
for (y=0; y &lt; (ssize_t) source-&gt;rows; y++)
{
  u=GetCacheViewVirtualPixels(view_1,0,y,source-&gt;columns,1,exception);
  v=GetCacheViewVirtualPixels(view_2,0,source-&gt;rows-y-1,source-&gt;columns,1,exception);
  if ((u == (const Quantum *) NULL) || (v == (const Quantum *) NULL))
    break;
  for (x=0; x &lt; (ssize_t) source-&gt;columns; x++)
  {
    /* do something with u &amp; v here */
  }
}
view_2=DestroyCacheView(view_2);
view_1=DestroyCacheView(view_1);
if (y &lt; (ssize_t) source-&gt;rows)
  { /* an exception was thrown */ }
</code></pre>

<h3>Magick Persistent Cache Format</h3>

<p>Recall that each image format is decoded by ImageMagick and the pixels are deposited in the pixel cache.  If you write an image, the pixels are read from the pixel cache and encoded as required by the format you are writing (e.g. GIF, PNG, etc.).  The Magick Persistent Cache (MPC) format is designed to eliminate the overhead of decoding and encoding pixels to and from an image format.  MPC writes two files.  One, with the extension <code>.mpc</code>, retains all the properties associated with the image or image sequence (e.g. width, height, colorspace, etc.) and the second, with the extension <code>.cache</code>, is the pixel cache in the native raw format.  When reading an MPC image file, ImageMagick reads the image properties and memory maps the pixel cache on disk eliminating the need for decoding the image pixels.  The tradeoff is in disk space.  MPC is generally larger in file size than most other image formats.</p>
<p>The most efficient use of MPC image files is a write-once, read-many-times pattern.  For example, your workflow requires extracting random blocks of pixels from the source image.  Rather than re-reading and possibly decompressing the source image each time, we use MPC and map the image directly to memory.</p>

<h3>Best Practices</h3>

<p>Although you can request any pixel from the pixel cache, any block of pixels, any scanline, multiple scanlines, any row, or multiple rows with the GetVirtualPixels(), GetAuthenticPixels(), QueueAuthenticPixels, GetCacheViewVirtualPixels(), GetCacheViewAuthenticPixels(), and QueueCacheViewAuthenticPixels() methods, ImageMagick is optimized to return a few pixels or a few pixels rows at time.  There are additional optimizations if you request a single scanline or a few scanlines at a time.  These methods also permit random access to the pixel cache, however, ImageMagick is optimized for sequential access.  Although you can access scanlines of pixels sequentially from the last row of the image to the first, you may get a performance boost if you access scanlines from the first row of the image to the last, in sequential order.</p>

<p>You can get, modify, or set pixels in row or column order.  However, it is more efficient to access the pixels by row rather than by column.</p>

<p>If you update pixels returned from GetAuthenticPixels() or GetCacheViewAuthenticPixels(), don't forget to call SyncAuthenticPixels() or SyncCacheViewAuthenticPixels() respectively to ensure your changes are synchronized with the pixel cache.</p>

<p>Use QueueAuthenticPixels() or QueueCacheViewAuthenticPixels() if you are setting an initial pixel value.  The GetAuthenticPixels() or GetCacheViewAuthenticPixels() method reads pixels from the cache and if you are setting an initial pixel value, this read is unnecessary. Don't forget to call SyncAuthenticPixels() or SyncCacheViewAuthenticPixels() respectively to push any pixel changes to the pixel cache.</p>

<p>GetVirtualPixels(), GetAuthenticPixels(), QueueAuthenticPixels(), and SyncAuthenticPixels() are slightly more efficient than their cache view counter-parts.  However, cache views are required if you need access to more than one region of the image simultaneously or if more than one <a href="#threads">thread of execution</a> is accessing the image.</p>

<p>You can request pixels outside the bounds of the image with GetVirtualPixels() or GetCacheViewVirtualPixels(), however, it is more efficient to request pixels within the confines of the image region.</p>

<p>Although you can force the pixel cache to disk using appropriate resource limits, disk access can be upwards of 1000 times slower than memory access.  For fast, efficient, access to the pixel cache, try to keep the pixel cache in heap memory.</p>

<p>The ImageMagick Q16 version of ImageMagick permits you to read and write 16 bit images without scaling but the pixel cache consumes twice as many resources as the Q8 version.  If your system has constrained memory or disk resources, consider the Q8 version of ImageMagick.  In addition, the Q8 version typically executes faster than the Q16 version.</p>

<p>A great majority of image formats and algorithms restrict themselves to a fixed range of pixel values from 0 to some maximum value, for example, the Q16 version of ImageMagick permit intensities from 0 to 65535.  High dynamic-range imaging (HDRI), however, permits a far greater dynamic range of exposures (i.e. a large difference between light and dark areas) than standard digital imaging techniques. HDRI accurately represents the wide range of intensity levels found in real scenes ranging from the brightest direct sunlight to the deepest darkest shadows.  Enable <a href="<?php echo $_SESSION['RelativePath']?>/../script/high-dynamic-range.php">HDRI</a> at ImageMagick build time to deal with high dynamic-range images, but be mindful that each pixel component is a 32-bit floating point value. In addition, pixel values are not clamped by default so some algorithms may have unexpected results due to out-of-band pixel values than the non-HDRI version.</p>

<p>If you are dealing with large images, make sure the pixel cache is written to a disk area with plenty of free space.  Under Unix, this is typically <code>/tmp</code> and for Windows, <code>c:/temp</code>.  You can tell ImageMagick to write the pixel cache to an alternate location and conserve memory with these options:</p>
<pre class="highlight"><code>convert -limit memory 2GB -limit map 4GB -define registry:temporary-path=/data/tmp ...
</code></pre>

<p>Set global resource limits for your environment in the <code>policy.xml</code> configuration file.</p>

<p>If you plan on processing the same image many times, consider the MPC format.  Reading a MPC image has near-zero overhead because its in the native pixel cache format eliminating the need for decoding the image pixels.  Here is an example:</p>
<pre class="highlight"><code>convert image.tif image.mpc
convert image.mpc -crop 100x100+0+0 +repage 1.png
convert image.mpc -crop 100x100+100+0 +repage 2.png
convert image.mpc -crop 100x100+200+0 +repage 3.png
</code></pre>

<p>MPC is ideal for web sites.  It reduces the overhead of reading and writing an image.  We use it exclusively at our <a href="https://www.imagemagick.org/MagickStudio/scripts/MagickStudio.cgi">online image studio</a>.</p>

<h2 class="magick-post-title"><a class="anchor" id="stream"></a>Streaming Pixels</h2>

<p>ImageMagick provides for streaming pixels as they are read from or written to an image.  This has several advantages over the pixel cache.  The time and resources consumed by the pixel cache scale with the area of an image, whereas the pixel stream resources scale with the width of an image.  The disadvantage is the pixels must be consumed as they are streamed so there is no persistence.</p>

<p>Use <a href="<?php echo $_SESSION['RelativePath']?>/../api/stream.php#ReadStream">ReadStream()</a> or <a href="<?php echo $_SESSION['RelativePath']?>/../api/stream.php#WriteStream">WriteStream()</a> with an appropriate callback method in your MagickCore program to consume the pixels as they are streaming.  Here's an abbreviated example of using ReadStream:</p>
<pre class="pre-scrollable"><code>static size_t StreamPixels(const Image *image,const void *pixels,const size_t columns)
{
  register const Quantum
    *p;

  MyData
    *my_data;

  my_data=(MyData *) image->client_data;
  p=(Quantum *) pixels;
  if (p != (const Quantum *) NULL)
    {
      /* process pixels here */
    }
  return(columns);
}

...

/* invoke the pixel stream here */
image_info->client_data=(void *) MyData;
image=ReadStream(image_info,&amp;StreamPixels,exception);
</code></pre>

<p>We also provide a lightweight tool, <a href="<?php echo $_SESSION['RelativePath']?>/../script/stream.php">stream</a>, to stream one or more pixel components of the image or portion of the image to your choice of storage formats.  It writes the pixel components as they are read from the input image a row at a time making <a href="<?php echo $_SESSION['RelativePath']?>/../script/stream.php">stream</a> desirable when working with large images or when you require raw pixel components.  A majority of the image formats stream pixels (red, green, and blue) from left to right and top to bottom.  However, a few formats do not support this common ordering (e.g. the PSD format).</p>

<h2 class="magick-post-title"><a class="anchor" id="properties"></a>Image Properties and Profiles</h2>

<p>Images have metadata associated with them in the form of properties (e.g. width, height, description, etc.) and profiles (e.g. EXIF, IPTC, color management).  ImageMagick provides convenient methods to get, set, or update image properties and get, set, update, or apply profiles.  Some of the more popular image properties are associated with the Image structure in the MagickCore API.  For example:</p>
<pre class="highlight"><code>(void) printf("image width: %lu, height: %lu\n",image-&gt;columns,image-&gt;rows);
</code></pre>

<p>For a great majority of image properties, such as an image comment or description, we use the <a href="<?php echo $_SESSION['RelativePath']?>/../api/property.php#GetImageProperty">GetImageProperty()</a> and <a href="<?php echo $_SESSION['RelativePath']?>/../api/property.php#SetImageProperty">SetImageProperty()</a> methods.  Here we set a property and fetch it right back:</p>
<pre class="highlight"><code>const char
  *comment;

(void) SetImageProperty(image,"comment","This space for rent");
comment=GetImageProperty(image,"comment");
if (comment == (const char *) NULL)
  (void) printf("Image comment: %s\n",comment);
</code></pre>

<p>ImageMagick supports artifacts with the GetImageArtifact() and SetImageArtifact() methods.  Artifacts are stealth properties that are not exported to image formats (e.g. PNG).</p>

<p>Image profiles are handled with <a href="<?php echo $_SESSION['RelativePath']?>/../api/profile.php#GetImageProfile">GetImageProfile()</a>, <a href="<?php echo $_SESSION['RelativePath']?>/../api/profile.php#SetImageProfile">SetImageProfile()</a>, and <a href="<?php echo $_SESSION['RelativePath']?>/../api/profile.php#ProfileImage">ProfileImage()</a> methods.  Here we set a profile and fetch it right back:</p>
<pre class="highlight"><code>StringInfo
  *profile;

profile=AcquireStringInfo(length);
SetStringInfoDatum(profile,my_exif_profile);
(void) SetImageProfile(image,"EXIF",profile);
DestroyStringInfo(profile);
profile=GetImageProfile(image,"EXIF");
if (profile != (StringInfo *) NULL)
  (void) PrintStringInfo(stdout,"EXIF",profile);
</code></pre>

<h2 class="magick-post-title"><a class="anchor" id="tera-pixel"></a>Large Image Support</h2>
<p>ImageMagick can read, process, or write mega-, giga-, or tera-pixel image sizes.  An image width or height can range from 1 to 2 giga-pixels on a 32 bit OS and up to 9 exa-pixels on a 64-bit OS.  Note, that some image formats have restrictions on image size.  For example, Photoshop images are limited to 300,000 pixels for width or height.  Here we resize an image to a quarter million pixels square:</p>
<pre class="highlight"><code>convert logo: -resize 250000x250000 logo.miff
</code></pre>

<p>For large images, ImageMagick will likely create a pixel cache on disk.  Make sure you have plenty of temporary disk space.  If your default temporary disk partition is too small, tell ImageMagick to use another partition with plenty of free space.  For example:</p>
<pre class="highlight"><code>convert -define registry:temporary-path=/data/tmp logo:  \ <br/>     -resize 250000x250000 logo.miff
</code></pre>

<p>To ensure large images do not consume all the memory on your system, force the image pixels to memory-mapped disk with resource limits:</p>
<pre class="highlight"><code>convert -define registry:temporary-path=/data/tmp -limit memory 16mb \
  logo: -resize 250000x250000 logo.miff
</code></pre>

<p>Here we force all image pixels to disk:</p>
<pre class="highlight"><code>convert -define registry:temporary-path=/data/tmp -limit area 0 \
  logo: -resize 250000x250000 logo.miff
</code></pre>

<p>Caching pixels to disk is about 1000 times slower than memory.  Expect long run times when processing large images on disk with ImageMagick.  You can monitor progress with this command:</p>
<pre class="highlight"><code>convert -monitor -limit memory 2GiB -limit map 4GiB -define registry:temporary-path=/data/tmp \
  logo: -resize 250000x250000 logo.miff
</code></pre>

<p>For really large images, or if there is limited resources on your host, you can utilize a distributed pixel cache on one or more remote hosts:</p>
<pre class="highlight"><code>convert -distribute-cache 6668 &amp;  // start on 192.168.100.50
convert -distribute-cache 6668 &amp;  // start on 192.168.100.51
convert -limit memory 2mb -limit map 2mb -limit disk 2gb \
  -define registry:cache:hosts=192.168.100.50:6668,192.168.100.51:6668 \
  myhugeimage.jpg -sharpen 5x2 myhugeimage.png
</code></pre>
<p>Due to network latency, expect a substantial slow-down in processing your workflow.</p>

<h2 class="magick-post-title"><a class="anchor" id="threads"></a>Threads of Execution</h2>

<p>Many of ImageMagick's internal algorithms are threaded to take advantage of speed-ups offered by the multicore processor chips. However, you are welcome to use ImageMagick algorithms in your threads of execution with the exception of the MagickCore's GetVirtualPixels(), GetAuthenticPixels(), QueueAuthenticPixels(), or SyncAuthenticPixels() pixel cache methods.  These methods are intended for one thread of execution only with the exception of an OpenMP parallel section.  To access the pixel cache with more than one thread of execution, use a cache view.  We do this for the <a href="<?php echo $_SESSION['RelativePath']?>/../api/composite.php#CompositeImage">CompositeImage()</a> method, for example.  Suppose we want to composite a single source image over a different destination image in each thread of execution.  If we use GetVirtualPixels(), the results are unpredictable because multiple threads would likely be asking for different areas of the pixel cache simultaneously.  Instead we use GetCacheViewVirtualPixels() which creates a unique view for each thread of execution ensuring our program behaves properly regardless of how many threads are invoked.  The other program interfaces, such as the <a href="<?php echo $_SESSION['RelativePath']?>/../script/magick-wand.php">MagickWand API</a>, are completely thread safe so there are no special precautions for threads of execution.</p>

<p>Here is an MagickCore code snippet that takes advantage of threads of execution with the <a href="<?php echo $_SESSION['RelativePath']?>/../script/openmp.php">OpenMP</a> programming paradigm:</p>
<pre class="pre-scrollable"><code>CacheView
  *image_view;

MagickBooleanType
  status;

ssize_t
  y;

status=MagickTrue;
image_view=AcquireVirtualCacheView(image,exception);
#pragma omp parallel for schedule(static,4) shared(status)
for (y=0; y &lt; (ssize_t) image-&gt;rows; y++)
{
  register Quantum
    *q;

  register ssize_t
    x;

  register void
    *metacontent;

  if (status == MagickFalse)
    continue;
  q=GetCacheViewAuthenticPixels(image_view,0,y,image-&gt;columns,1,exception);
  if (q == (Quantum *) NULL)
    {
      status=MagickFalse;
      continue;
    }
  metacontent=GetCacheViewAuthenticMetacontent(image_view);
  for (x=0; x &lt; (ssize_t) image-&gt;columns; x++)
  {
    SetPixelRed(image,...,q);
    SetPixelGreen(image,...,q);
    SetPixelBlue(image,...,q);
    SetPixelAlpha(image,...,q);
    if (metacontent != NULL)
      metacontent[indexes+x]=...;
    q+=GetPixelChannels(image);
  }
  if (SyncCacheViewAuthenticPixels(image_view,exception) == MagickFalse)
    status=MagickFalse;
}
image_view=DestroyCacheView(image_view);
if (status == MagickFalse)
  perror("something went wrong");
</code></pre>

<p>This code snippet converts an uncompressed Windows bitmap to a Magick++ image:</p>
<pre class="pre-scrollable"><code>#include "Magick++.h"
#include &lt;assert.h&gt;
#include "omp.h"

void ConvertBMPToImage(const BITMAPINFOHEADER *bmp_info,
  const unsigned char *restrict pixels,Magick::Image *image)
{
  /*
    Prepare the image so that we can modify the pixels directly.
  */
  assert(bmp_info->biCompression == BI_RGB);
  assert(bmp_info->biWidth == image->columns());
  assert(abs(bmp_info->biHeight) == image->rows());
  image->modifyImage();
  if (bmp_info->biBitCount == 24)
    image->type(MagickCore::TrueColorType);
  else
    image->type(MagickCore::TrueColorMatteType);
  register unsigned int bytes_per_row=bmp_info->biWidth*bmp_info->biBitCount/8;
  if (bytes_per_row % 4 != 0) {
    bytes_per_row=bytes_per_row+(4-bytes_per_row % 4);  // divisible by 4.
  }
  /*
    Copy all pixel data, row by row.
  */
  #pragma omp parallel for
  for (int y=0; y &lt; int(image->rows()); y++)
  {
    int
      row;

    register const unsigned char
      *restrict p;

    register MagickCore::Quantum
      *restrict q;

    row=(bmp_info->biHeight > 0) ? (image->rows()-y-1) : y;
    p=pixels+row*bytes_per_row;
    q=image->setPixels(0,y,image->columns(),1);
    for (int x=0; x &lt; int(image->columns()); x++)
    {
      SetPixelBlue(image,p[0],q);
      SetPixelGreen(image,p[1],q);
      SetPixelRed(image,p[2],q);
      if (bmp_info->biBitCount == 32) {
        SetPixelAlpha(image,p[3],q);
      }
      q+=GetPixelChannels(image);
      p+=bmp_info->biBitCount/8;
    }
    image->syncPixels();  // sync pixels to pixel cache.
  }
  return;
}</code></pre>

<p>If you call the ImageMagick API from your OpenMP-enabled application and you intend to dynamically increase the number of threads available in subsequent parallel regions, be sure to perform the increase <var>before</var> you call the API otherwise ImageMagick may fault.</p>

<p><a href="<?php echo $_SESSION['RelativePath']?>/../api/wand-view.php">MagickWand</a> supports wand views.  A view iterates over the entire, or portion, of the image in parallel and for each row of pixels, it invokes a callback method you provide.  This limits most of your parallel programming activity to just that one module.  There are similar methods in <a href="<?php echo $_SESSION['RelativePath']?>/../api/image-view.php">MagickCore</a>.  For an example, see the same sigmoidal contrast algorithm implemented in both <a href="<?php echo $_SESSION['RelativePath']?>/../script/magick-wand.php#wand-view">MagickWand</a> and <a href="<?php echo $_SESSION['RelativePath']?>/../script/magick-core.php#image-view">MagickCore</a>.</p>

<p>In most circumstances, the default number of threads is set to the number of processor cores on your system for optimal performance.  However, if your system is hyperthreaded or if you are running on a virtual host and only a subset of the processors are available to your server instance, you might get an increase in performance by setting the thread <a href="<?php echo $_SESSION['RelativePath']?>/../script/resources.php#configure">policy</a> or the <a href="<?php echo $_SESSION['RelativePath']?>/../script/resources.php#environment">MAGICK_THREAD_LIMIT</a> environment variable.  For example, your virtual host has 8 processors but only 2 are assigned to your server instance.  The default of 8 threads can cause severe performance problems.  One solution is to limit the number of threads to the available processors in your <a href="<?php echo $_SESSION['RelativePath']?>/../source/policy.xml">policy.xml</a> configuration file:</p>
<pre class="highlight"><code>&lt;policy domain="resource" name="thread" value="2"/>
</code></pre>

<p>Or suppose your 12 core hyperthreaded computer defaults to 24 threads.  Set the MAGICK_THREAD_LIMIT environment variable and you will likely get improved performance:</p>
<pre class="highlight"><code>export MAGICK_THREAD_LIMIT=12
</code></pre>

<p>The OpenMP committee has not defined the behavior of mixing OpenMP with other threading models such as Posix threads.  However, using modern releases of Linux, OpenMP and Posix threads appear to interoperate without complaint.  If you want to use Posix threads from a program module that calls one of the ImageMagick application programming interfaces (e.g. MagickCore, MagickWand, Magick++, etc.) from Mac OS X or an older Linux release, you may need to disable OpenMP support within ImageMagick.  Add the <code>--disable-openmp</code> option to the configure script command line and rebuild and reinstall ImageMagick.</p>

<h4>Threading Performance</h4>
<p>It can be difficult to predict behavior in a parallel environment.   Performance might depend on a number of factors including the compiler, the version of the OpenMP library, the processor type, the number of cores, the amount of memory, whether hyperthreading is enabled, the mix of applications that are executing concurrently with ImageMagick, or the particular image-processing algorithm you utilize.  The only way to be certain of optimal performance, in terms of the number of threads, is to benchmark.   ImageMagick includes progressive threading when benchmarking a command and returns the elapsed time and efficiency for one or more threads.  This can help you identify how many threads is the most efficient in your environment.  For this benchmark we sharpen a 1920x1080 image of a model 10 times with 1 to 12 threads:</p>
<pre class="highlight">-> convert -bench 10 model.png -sharpen 5x2 null:
Performance[1]: 10i 1.135ips 1.000e 8.760u 0:08.810
Performance[2]: 10i 2.020ips 0.640e 9.190u 0:04.950
Performance[3]: 10i 2.786ips 0.710e 9.400u 0:03.590
Performance[4]: 10i 3.378ips 0.749e 9.580u 0:02.960
Performance[5]: 10i 4.032ips 0.780e 9.580u 0:02.480
Performance[6]: 10i 4.566ips 0.801e 9.640u 0:02.190
Performance[7]: 10i 3.788ips 0.769e 10.980u 0:02.640
Performance[8]: 10i 4.115ips 0.784e 12.030u 0:02.430
Performance[9]: 10i 4.484ips 0.798e 12.860u 0:02.230
Performance[10]: 10i 4.274ips 0.790e 14.830u 0:02.340
Performance[11]: 10i 4.348ips 0.793e 16.500u 0:02.300
Performance[12]: 10i 4.525ips 0.799e 18.320u 0:02.210
</pre>
<p>The sweet spot for this example is 6 threads. This makes sense since there are 6 physical cores.  The other 6 are hyperthreads. It appears that sharpening does not benefit from hyperthreading.</p>
<p>In certain cases, it might be optimal to set the number of threads to 1 or to disable OpenMP completely with the <a href="<?php echo $_SESSION['RelativePath']?>/../script/resources.php#environment">MAGICK_THREAD_LIMIT</a> environment variable, <a href="<?php echo $_SESSION['RelativePath']?>/../script/command-line-options.php#limit">-limit</a> command line option,  or the  <a href="<?php echo $_SESSION['RelativePath']?>/../script/resources.php#configure">policy.xml</a> configuration file.</p>

<h2 class="magick-post-title"><a class="anchor" id="distributed"></a>Heterogeneous Distributed Processing</h2>
<p>ImageMagick includes support for heterogeneous distributed processing with the <a href="http://en.wikipedia.org/wiki/OpenCL">OpenCL</a> framework.  OpenCL kernels within ImageMagick permit image processing algorithms to execute across heterogeneous platforms consisting of CPUs, GPUs, and other processors.  Depending on your platform, speed-ups can be an order of magnitude faster than the traditional single CPU.</p>

<p>First verify that your version of ImageMagick includes support for the OpenCL feature:</p>
<pre class="highlight"><code>identify -version
Features: DPC Cipher Modules OpenCL OpenMP
</code></pre>

<p>If so, run this command to realize a significant speed-up for image convolution:</p>

<pre class="highlight"><code>convert image.png -convolve '-1, -1, -1, -1, 9, -1, -1, -1, -1' convolve.png
</code></pre>

<p>If an accelerator is not available or if the accelerator fails to respond, ImageMagick reverts to the non-accelerated convolution algorithm.</p>

<p>Here is an example OpenCL kernel that convolves an image:</p>
<pre class="pre-scrollable"><code>static inline long ClampToCanvas(const long offset,const ulong range)
{
  if (offset &lt; 0L)
    return(0L);
  if (offset >= range)
    return((long) (range-1L));
  return(offset);
}

static inline CLQuantum ClampToQuantum(const float value)
{
  if (value &lt; 0.0)
    return((CLQuantum) 0);
  if (value >= (float) QuantumRange)
    return((CLQuantum) QuantumRange);
  return((CLQuantum) (value+0.5));
}

__kernel void Convolve(const __global CLPixelType *source,__constant float *filter,
  const ulong width,const ulong height,__global CLPixelType *destination)
{
  const ulong columns = get_global_size(0);
  const ulong rows = get_global_size(1);

  const long x = get_global_id(0);
  const long y = get_global_id(1);

  const float scale = (1.0/QuantumRange);
  const long mid_width = (width-1)/2;
  const long mid_height = (height-1)/2;
  float4 sum = { 0.0, 0.0, 0.0, 0.0 };
  float gamma = 0.0;
  register ulong i = 0;

  for (long v=(-mid_height); v &lt;= mid_height; v++)
  {
    for (long u=(-mid_width); u &lt;= mid_width; u++)
    {
      register const ulong index=ClampToCanvas(y+v,rows)*columns+ClampToCanvas(x+u,
        columns);
      const float alpha=scale*(QuantumRange-source[index].w);
      sum.x+=alpha*filter[i]*source[index].x;
      sum.y+=alpha*filter[i]*source[index].y;
      sum.z+=alpha*filter[i]*source[index].z;
      sum.w+=filter[i]*source[index].w;
      gamma+=alpha*filter[i];
      i++;
    }
  }

  gamma=1.0/(fabs(gamma) &lt;= MagickEpsilon ? 1.0 : gamma);
  const ulong index=y*columns+x;
  destination[index].x=ClampToQuantum(gamma*sum.x);
  destination[index].y=ClampToQuantum(gamma*sum.y);
  destination[index].z=ClampToQuantum(gamma*sum.z);
  destination[index].w=ClampToQuantum(sum.w);
};</code></pre>

<p>See <a href="https://github.com/ImageMagick/ImageMagick/tree/ImageMagick-7/magick/accelerate.c">magick/accelerate.c</a> for a complete implementation of image convolution with an OpenCL kernel.</p>

<p>Note, that under Windows, you might have an issue with TDR (Timeout Detection and Recovery of GPUs). Its purpose is to detect runaway tasks hanging the GPU by using an execution time threshold.  For some older low-end GPUs running the OpenCL filters in ImageMagick, longer execution times might trigger the TDR mechanism and pre-empt the GPU image filter.  When this happens, ImageMagick automatically falls back to the CPU code path and returns the expected results.  To avoid pre-emption, increase the <a href="http://msdn.microsoft.com/en-us/library/windows/hardware/gg487368.aspx">TdrDelay</a> registry key.</p>

<h2 class="magick-post-title"><a class="anchor" id="coders"></a>Custom Image Coders</h2>

<p>An image coder (i.e. encoder / decoder) is responsible for registering, optionally classifying, optionally reading, optionally writing, and unregistering one image format (e.g.  PNG, GIF, JPEG, etc.).  Registering an image coder alerts ImageMagick a particular format is available to read or write.  While unregistering tells ImageMagick the format is no longer available.  The classifying method looks at the first few bytes of an image and determines if the image is in the expected format.  The reader sets the image size, colorspace, and other properties and loads the pixel cache with the pixels.  The reader returns a single image or an image sequence (if the format supports multiple images per file), or if an error occurs, an exception and a null image.  The writer does the reverse.  It takes the image properties and unloads the pixel cache and writes them as required by the image format.</p>

<p>Here is a listing of a sample <a href="<?php echo $_SESSION['RelativePath']?>/../source/mgk.c">custom coder</a>.  It reads and writes images in the MGK image format which is simply an ID followed by the image width and height followed by the RGB pixel values.</p>
<pre class="pre-scrollable"><code>#include &lt;MagickCore/studio.h>
#include &lt;MagickCore/blob.h>
#include &lt;MagickCore/cache.h>
#include &lt;MagickCore/colorspace.h>
#include &lt;MagickCore/exception.h>
#include &lt;MagickCore/image.h>
#include &lt;MagickCore/list.h>
#include &lt;MagickCore/magick.h>
#include &lt;MagickCore/memory_.h>
#include &lt;MagickCore/monitor.h>
#include &lt;MagickCore/pixel-accessor.h>
#include &lt;MagickCore/string_.h>
#include &lt;MagickCore/module.h>
#include "filter/blob-private.h"
#include "filter/exception-private.h"
#include "filter/image-private.h"
#include "filter/monitor-private.h"
#include "filter/quantum-private.h"

/*
  Forward declarations.
*/
static MagickBooleanType
  WriteMGKImage(const ImageInfo *,Image *,ExceptionInfo *);

/*
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%
%                                                                             %
%                                                                             %
%                                                                             %
%   I s M G K                                                                 %
%                                                                             %
%                                                                             %
%                                                                             %
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%
%
%  IsMGK() returns MagickTrue if the image format type, identified by the
%  magick string, is MGK.
%
%  The format of the IsMGK method is:
%
%      MagickBooleanType IsMGK(const unsigned char *magick,const size_t length)
%
%  A description of each parameter follows:
%
%    o magick: This string is generally the first few bytes of an image file
%      or blob.
%
%    o length: Specifies the length of the magick string.
%
*/
static MagickBooleanType IsMGK(const unsigned char *magick,const size_t length)
{
  if (length &lt; 7)
    return(MagickFalse);
  if (LocaleNCompare((char *) magick,"id=mgk",7) == 0)
    return(MagickTrue);
  return(MagickFalse);
}

/*
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%
%                                                                             %
%                                                                             %
%                                                                             %
%   R e a d M G K I m a g e                                                   %
%                                                                             %
%                                                                             %
%                                                                             %
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%
%
%  ReadMGKImage() reads a MGK image file and returns it.  It allocates the
%  memory necessary for the new Image structure and returns a pointer to the
%  new image.
%
%  The format of the ReadMGKImage method is:
%
%      Image *ReadMGKImage(const ImageInfo *image_info,
%        ExceptionInfo *exception)
%
%  A description of each parameter follows:
%
%    o image_info: the image info.
%
%    o exception: return any errors or warnings in this structure.
%
*/
static Image *ReadMGKImage(const ImageInfo *image_info,ExceptionInfo *exception)
{
  char
    buffer[MaxTextExtent];

  Image
    *image;

  long
    y;

  MagickBooleanType
    status;

  register long
    x;

  register Quantum
    *q;

  register unsigned char
    *p;

  ssize_t
    count;

  unsigned char
    *pixels;

  unsigned long
    columns,
    rows;

  /*
    Open image file.
  */
  assert(image_info != (const ImageInfo *) NULL);
  assert(image_info->signature == MagickCoreSignature);
  if (image_info->debug != MagickFalse)
    (void) LogMagickEvent(TraceEvent,GetMagickModule(),"%s",
      image_info->filename);
  assert(exception != (ExceptionInfo *) NULL);
  assert(exception->signature == MagickCoreSignature);
  image=AcquireImage(image_info,exception);
  status=OpenBlob(image_info,image,ReadBinaryBlobMode,exception);
  if (status == MagickFalse)
    {
      image=DestroyImageList(image);
      return((Image *) NULL);
    }
  /*
    Read MGK image.
  */
  (void) ReadBlobString(image,buffer);  /* read magic number */
  if (IsMGK(buffer,7) == MagickFalse)
    ThrowReaderException(CorruptImageError,"ImproperImageHeader");
  (void) ReadBlobString(image,buffer);
  count=(ssize_t) sscanf(buffer,"%lu %lu\n",&columns,&rows);
  if (count &lt;= 0)
    ThrowReaderException(CorruptImageError,"ImproperImageHeader");
  do
  {
    /*
      Initialize image structure.
    */
    image->columns=columns;
    image->rows=rows;
    image->depth=8;
    if ((image_info->ping != MagickFalse) && (image_info->number_scenes != 0))
      if (image->scene >= (image_info->scene+image_info->number_scenes-1))
        break;
    /*
      Convert MGK raster image to pixel packets.
    */
    if (SetImageExtent(image,image->columns,image->rows,exception) == MagickFalse)
      return(DestroyImageList(image));
    pixels=(unsigned char *) AcquireQuantumMemory((size_t) image->columns,
      3UL*sizeof(*pixels));
    if (pixels == (unsigned char *) NULL)
      ThrowReaderException(ResourceLimitError,"MemoryAllocationFailed");
    for (y=0; y &lt; (long) image->rows; y++)
    {
      count=(ssize_t) ReadBlob(image,(size_t) (3*image->columns),pixels);
      if (count != (ssize_t) (3*image->columns))
        ThrowReaderException(CorruptImageError,"UnableToReadImageData");
      p=pixels;
      q=QueueAuthenticPixels(image,0,y,image->columns,1,exception);
      if (q == (Quantum *) NULL)
        break;
      for (x=0; x &lt; (long) image->columns; x++)
      {
        SetPixelRed(image,ScaleCharToQuantum(*p++),q);
        SetPixelGreen(image,ScaleCharToQuantum(*p++),q);
        SetPixelBlue(image,ScaleCharToQuantum(*p++),q);
        q+=GetPixelChannels(image);
      }
      if (SyncAuthenticPixels(image,exception) == MagickFalse)
        break;
      if (image->previous == (Image *) NULL)
        if ((image->progress_monitor != (MagickProgressMonitor) NULL) &&
            (QuantumTick(y,image->rows) != MagickFalse))
          {
            status=image->progress_monitor(LoadImageTag,y,image->rows,
              image->client_data);
            if (status == MagickFalse)
              break;
          }
    }
    pixels=(unsigned char *) RelinquishMagickMemory(pixels);
    if (EOFBlob(image) != MagickFalse)
      {
        ThrowFileException(exception,CorruptImageError,"UnexpectedEndOfFile",
          image->filename);
        break;
      }
    /*
      Proceed to next image.
    */
    if (image_info->number_scenes != 0)
      if (image->scene >= (image_info->scene+image_info->number_scenes-1))
        break;
    *buffer='\0';
    (void) ReadBlobString(image,buffer);
    count=(ssize_t) sscanf(buffer,"%lu %lu\n",&columns,&rows);
    if (count > 0)
      {
        /*
          Allocate next image structure.
        */
        AcquireNextImage(image_info,image,exception);
        if (GetNextImageInList(image) == (Image *) NULL)
          {
            image=DestroyImageList(image);
            return((Image *) NULL);
          }
        image=SyncNextImageInList(image);
        if (image->progress_monitor != (MagickProgressMonitor) NULL)
          {
            status=SetImageProgress(image,LoadImageTag,TellBlob(image),
              GetBlobSize(image));
            if (status == MagickFalse)
              break;
          }
      }
  } while (count > 0);
  (void) CloseBlob(image);
  return(GetFirstImageInList(image));
}

/*
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%
%                                                                             %
%                                                                             %
%                                                                             %
%   R e g i s t e r M G K I m a g e                                           %
%                                                                             %
%                                                                             %
%                                                                             %
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%
%
%  RegisterMGKImage() adds attributes for the MGK image format to
%  the list of supported formats.  The attributes include the image format
%  tag, a method to read and/or write the format, whether the format
%  supports the saving of more than one frame to the same file or blob,
%  whether the format supports native in-memory I/O, and a brief
%  description of the format.
%
%  The format of the RegisterMGKImage method is:
%
%      unsigned long RegisterMGKImage(void)
%
*/
ModuleExport unsigned long RegisterMGKImage(void)
{
  MagickInfo
    *entry;

  entry=AcquireMagickInfo("MGK","MGK","MGK image");
  entry->decoder=(DecodeImageHandler *) ReadMGKImage;
  entry->encoder=(EncodeImageHandler *) WriteMGKImage;
  entry->magick=(IsImageFormatHandler *) IsMGK;
  (void) RegisterMagickInfo(entry);
  return(MagickImageCoderSignature);
}

/*
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%
%                                                                             %
%                                                                             %
%                                                                             %
%   U n r e g i s t e r M G K I m a g e                                       %
%                                                                             %
%                                                                             %
%                                                                             %
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%
%
%  UnregisterMGKImage() removes format registrations made by the
%  MGK module from the list of supported formats.
%
%  The format of the UnregisterMGKImage method is:
%
%      UnregisterMGKImage(void)
%
*/
ModuleExport void UnregisterMGKImage(void)
{
  (void) UnregisterMagickInfo("MGK");
}

/*
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%
%                                                                             %
%                                                                             %
%                                                                             %
%   W r i t e M G K I m a g e                                                 %
%                                                                             %
%                                                                             %
%                                                                             %
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%
%
%  WriteMGKImage() writes an image to a file in red, green, and blue MGK
%  rasterfile format.
%
%  The format of the WriteMGKImage method is:
%
%      MagickBooleanType WriteMGKImage(const ImageInfo *image_info,
%        Image *image)
%
%  A description of each parameter follows.
%
%    o image_info: the image info.
%
%    o image:  The image.
%
%    o exception:  return any errors or warnings in this structure.
%
*/
static MagickBooleanType WriteMGKImage(const ImageInfo *image_info,Image *image,
  ExceptionInfo *exception)
{
  char
    buffer[MaxTextExtent];

  long
    y;

  MagickBooleanType
    status;

  MagickOffsetType
    scene;

  register const Quantum
    *p;

  register long
    x;

  register unsigned char
    *q;

  unsigned char
    *pixels;

  /*
    Open output image file.
  */
  assert(image_info != (const ImageInfo *) NULL);
  assert(image_info->signature == MagickCoreSignature);
  assert(image != (Image *) NULL);
  assert(image->signature == MagickCoreSignature);
  if (image->debug != MagickFalse)
    (void) LogMagickEvent(TraceEvent,GetMagickModule(),"%s",image->filename);
  status=OpenBlob(image_info,image,WriteBinaryBlobMode,exception);
  if (status == MagickFalse)
    return(status);
  scene=0;
  do
  {
    /*
      Allocate memory for pixels.
    */
    if (image->colorspace != RGBColorspace)
      (void) SetImageColorspace(image,RGBColorspace,exception);
    pixels=(unsigned char *) AcquireQuantumMemory((size_t) image->columns,
      3UL*sizeof(*pixels));
    if (pixels == (unsigned char *) NULL)
      ThrowWriterException(ResourceLimitError,"MemoryAllocationFailed");
    /*
      Initialize raster file header.
    */
    (void) WriteBlobString(image,"id=mgk\n");
    (void) FormatLocaleString(buffer,MaxTextExtent,"%lu %lu\n",image->columns,
       image->rows);
    (void) WriteBlobString(image,buffer);
    for (y=0; y &lt; (long) image->rows; y++)
    {
      p=GetVirtualPixels(image,0,y,image->columns,1,exception);
      if (p == (const Quantum *) NULL)
        break;
      q=pixels;
      for (x=0; x &lt; (long) image->columns; x++)
      {
        *q++=ScaleQuantumToChar(GetPixelRed(image,p));
        *q++=ScaleQuantumToChar(GetPixelGreen(image,p));
        *q++=ScaleQuantumToChar(GetPixelBlue(image,p));
        p+=GetPixelChannels(image);
      }
      (void) WriteBlob(image,(size_t) (q-pixels),pixels);
      if (image->previous == (Image *) NULL)
        if ((image->progress_monitor != (MagickProgressMonitor) NULL) &&
            (QuantumTick(y,image->rows) != MagickFalse))
          {
            status=image->progress_monitor(SaveImageTag,y,image->rows,
              image->client_data);
            if (status == MagickFalse)
              break;
          }
    }
    pixels=(unsigned char *) RelinquishMagickMemory(pixels);
    if (GetNextImageInList(image) == (Image *) NULL)
      break;
    image=SyncNextImageInList(image);
    status=SetImageProgress(image,SaveImagesTag,scene,
      GetImageListLength(image));
    if (status == MagickFalse)
      break;
    scene++;
  } while (image_info->adjoin != MagickFalse);
  (void) CloseBlob(image);
  return(MagickTrue);
}</code></pre>

<p>To invoke the custom coder from the command line, use these commands:</p>
<pre class="highlight"><code>convert logo: logo.mgk
display logo.mgk
</code></pre>

<p>We provide the <a href="https://www.imagemagick.org/download/kits/">Magick Coder Kit</a> to help you get started writing your own custom coder.</p>

<h2 class="magick-post-title"><a class="anchor" id="filters"></a>Custom Image Filters</h2>

<p>ImageMagick provides a convenient mechanism for adding your own custom image processing algorithms.  We call these image filters and they are invoked from the command line with the <a href="<?php echo $_SESSION['RelativePath']?>/../script/command-line-options.php#process">-process</a> option or from the MagickCore API method <a href="<?php echo $_SESSION['RelativePath']?>/../api/module.php#ExecuteModuleProcess">ExecuteModuleProcess()</a>.</p>

<p>Here is a listing of a sample <a href="<?php echo $_SESSION['RelativePath']?>/../source/analyze.c">custom image filter</a>.  It computes a few statistics such as the pixel brightness and saturation mean and standard-deviation.</p>
<pre class="pre-scrollable"><code>#include &lt;stdio.h>
#include &lt;stdlib.h>
#include &lt;string.h>
#include &lt;time.h>
#include &lt;assert.h>
#include &lt;math.h>
#include &lt;MagickCore/MagickCore.h>

/*
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%
%                                                                             %
%                                                                             %
%                                                                             %
%   a n a l y z e I m a g e                                                   %
%                                                                             %
%                                                                             %
%                                                                             %
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%
%
%  analyzeImage() computes the brightness and saturation mean,  standard
%  deviation, kurtosis and skewness and stores these values as attributes 
%  of the image.
%
%  The format of the analyzeImage method is:
%
%      size_t analyzeImage(Image *images,const int argc,char **argv,
%        ExceptionInfo *exception)
%
%  A description of each parameter follows:
%
%    o image: the address of a structure of type Image.
%
%    o argc: Specifies a pointer to an integer describing the number of
%      elements in the argument vector.
%
%    o argv: Specifies a pointer to a text array containing the command line
%      arguments.
%
%    o exception: return any errors or warnings in this structure.
%
*/

static void ConvertRGBToHSB(const double red,const double green,
  const double blue,double *hue,double *saturation,double *brightness)
{
  double
    delta,
    max,
    min;

  /*
    Convert RGB to HSB colorspace.
  */
  assert(hue != (double *) NULL);
  assert(saturation != (double *) NULL);
  assert(brightness != (double *) NULL);
  *hue=0.0;
  *saturation=0.0;
  *brightness=0.0;
  min=red &lt; green ? red : green;
  if (blue &lt; min)
    min=blue;
  max=red > green ? red : green;
  if (blue > max)
    max=blue;
  if (fabs(max) &lt; MagickEpsilon)
    return;
  delta=max-min;
  *saturation=delta/max;
  *brightness=QuantumScale*max;
  if (fabs(delta) &lt; MagickEpsilon)
    return;
  if (fabs(red-max) &lt; MagickEpsilon)
    *hue=(green-blue)/delta;
  else
    if (fabs(green-max) &lt; MagickEpsilon)
      *hue=2.0+(blue-red)/delta;
    else
      *hue=4.0+(red-green)/delta;
  *hue/=6.0;
  if (*hue &lt; 0.0)
    *hue+=1.0;
}

ModuleExport size_t analyzeImage(Image **images,const int argc,
  const char **argv,ExceptionInfo *exception)
{
  char
    text[MaxTextExtent];

  double
    area,
    brightness,
    brightness_mean,
    brightness_standard_deviation,
    brightness_kurtosis,
    brightness_skewness,
    brightness_sum_x,
    brightness_sum_x2,
    brightness_sum_x3,
    brightness_sum_x4,
    hue,
    saturation,
    saturation_mean,
    saturation_standard_deviation,
    saturation_kurtosis,
    saturation_skewness,
    saturation_sum_x,
    saturation_sum_x2,
    saturation_sum_x3,
    saturation_sum_x4;

  Image
    *image;

  assert(images != (Image **) NULL);
  assert(*images != (Image *) NULL);
  assert((*images)->signature == MagickCoreSignature);
  (void) argc;
  (void) argv;
  image=(*images);
  for ( ; image != (Image *) NULL; image=GetNextImageInList(image))
  {
    CacheView
      *image_view;

    long
      y;

    MagickBooleanType
      status;

    brightness_sum_x=0.0;
    brightness_sum_x2=0.0;
    brightness_sum_x3=0.0;
    brightness_sum_x4=0.0;
    brightness_mean=0.0;
    brightness_standard_deviation=0.0;
    brightness_kurtosis=0.0;
    brightness_skewness=0.0;
    saturation_sum_x=0.0;
    saturation_sum_x2=0.0;
    saturation_sum_x3=0.0;
    saturation_sum_x4=0.0;
    saturation_mean=0.0;
    saturation_standard_deviation=0.0;
    saturation_kurtosis=0.0;
    saturation_skewness=0.0;
    area=0.0;
    status=MagickTrue;
    image_view=AcquireVirtualCacheView(image,exception);
#if defined(MAGICKCORE_OPENMP_SUPPORT)
    #pragma omp parallel for schedule(static,4) shared(status)
#endif
    for (y=0; y &lt; (long) image->rows; y++)
    {
      register const Quantum
        *p;

      register long
        x;

      if (status == MagickFalse)
        continue;
      p=GetCacheViewVirtualPixels(image_view,0,y,image->columns,1,exception);
      if (p == (const Quantum *) NULL)
        {
          status=MagickFalse;
          continue;
        }
      for (x=0; x &lt; (long) image->columns; x++)
      {
        ConvertRGBToHSB(GetPixelRed(image,p),GetPixelGreen(image,p),
          GetPixelBlue(image,p),&hue,&saturation,&brightness);
        brightness*=QuantumRange;
        brightness_sum_x+=brightness;
        brightness_sum_x2+=brightness*brightness;
        brightness_sum_x3+=brightness*brightness*brightness;
        brightness_sum_x4+=brightness*brightness*brightness*brightness;
        saturation*=QuantumRange;
        saturation_sum_x+=saturation;
        saturation_sum_x2+=saturation*saturation;
        saturation_sum_x3+=saturation*saturation*saturation;
        saturation_sum_x4+=saturation*saturation*saturation*saturation;
        area++;
        p+=GetPixelChannels(image);
      }
    }
    image_view=DestroyCacheView(image_view);
    if (area &lt;= 0.0)
      break;
    brightness_mean=brightness_sum_x/area;
    (void) FormatLocaleString(text,MaxTextExtent,"%g",brightness_mean);
    (void) SetImageProperty(image,"filter:brightness:mean",text,exception);
    brightness_standard_deviation=sqrt(brightness_sum_x2/area-(brightness_sum_x/
      area*brightness_sum_x/area));
    (void) FormatLocaleString(text,MaxTextExtent,"%g",
      brightness_standard_deviation);
    (void) SetImageProperty(image,"filter:brightness:standard-deviation",text,
      exception);
    if (brightness_standard_deviation != 0)
      brightness_kurtosis=(brightness_sum_x4/area-4.0*brightness_mean*
        brightness_sum_x3/area+6.0*brightness_mean*brightness_mean*
        brightness_sum_x2/area-3.0*brightness_mean*brightness_mean*
        brightness_mean*brightness_mean)/(brightness_standard_deviation*
        brightness_standard_deviation*brightness_standard_deviation*
        brightness_standard_deviation)-3.0;
    (void) FormatLocaleString(text,MaxTextExtent,"%g",brightness_kurtosis);
    (void) SetImageProperty(image,"filter:brightness:kurtosis",text,
      exception);
    if (brightness_standard_deviation != 0)
      brightness_skewness=(brightness_sum_x3/area-3.0*brightness_mean*
        brightness_sum_x2/area+2.0*brightness_mean*brightness_mean*
        brightness_mean)/(brightness_standard_deviation*
        brightness_standard_deviation*brightness_standard_deviation);
    (void) FormatLocaleString(text,MaxTextExtent,"%g",brightness_skewness);
    (void) SetImageProperty(image,"filter:brightness:skewness",text,exception);
    saturation_mean=saturation_sum_x/area;
    (void) FormatLocaleString(text,MaxTextExtent,"%g",saturation_mean);
    (void) SetImageProperty(image,"filter:saturation:mean",text,exception);
    saturation_standard_deviation=sqrt(saturation_sum_x2/area-(saturation_sum_x/
      area*saturation_sum_x/area));
    (void) FormatLocaleString(text,MaxTextExtent,"%g",
      saturation_standard_deviation);
    (void) SetImageProperty(image,"filter:saturation:standard-deviation",text,
      exception);
    if (saturation_standard_deviation != 0)
      saturation_kurtosis=(saturation_sum_x4/area-4.0*saturation_mean*
        saturation_sum_x3/area+6.0*saturation_mean*saturation_mean*
        saturation_sum_x2/area-3.0*saturation_mean*saturation_mean*
        saturation_mean*saturation_mean)/(saturation_standard_deviation*
        saturation_standard_deviation*saturation_standard_deviation*
        saturation_standard_deviation)-3.0;
    (void) FormatLocaleString(text,MaxTextExtent,"%g",saturation_kurtosis);
    (void) SetImageProperty(image,"filter:saturation:kurtosis",text,exception);
    if (saturation_standard_deviation != 0)
      saturation_skewness=(saturation_sum_x3/area-3.0*saturation_mean*
        saturation_sum_x2/area+2.0*saturation_mean*saturation_mean*
        saturation_mean)/(saturation_standard_deviation*
        saturation_standard_deviation*saturation_standard_deviation);
    (void) FormatLocaleString(text,MaxTextExtent,"%g",saturation_skewness);
    (void) SetImageProperty(image,"filter:saturation:skewness",text,exception);
  }
  return(MagickImageFilterSignature);
}
</code></pre>

<p>To invoke the custom filter from the command line, use this command:</p>

<pre class="highlight"><code>convert logo: -process \"analyze\" -verbose info:
  Image: logo:
    Format: LOGO (ImageMagick Logo)
    Class: PseudoClass
    Geometry: 640x480
    ...
    filter:brightness:kurtosis: 8.17947
    filter:brightness:mean: 60632.1
    filter:brightness:skewness: -2.97118
    filter:brightness:standard-deviation: 13742.1
    filter:saturation:kurtosis: 4.33554
    filter:saturation:mean: 5951.55
    filter:saturation:skewness: 2.42848
    filter:saturation:standard-deviation: 15575.9
</code></pre>


<p>We provide the <a href="https://www.imagemagick.org/download/kits/">Magick Filter Kit</a> to help you get started writing your own custom image filter.</p>

</div>
