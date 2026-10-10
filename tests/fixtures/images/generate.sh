#!/usr/bin/env bash
#
# Builds the test images in this directory: flat colors in a range of shapes,
# each labelled with its name and shape, with known XMP metadata. The images
# are committed, so the tests do not need ImageMagick; run this to change or
# add one. The people and places are fictional.
#
# Requires ImageMagick (convert).
#
set -euo pipefail
cd "$(dirname "$0")"

# name  width  height  color  title  person  genre
IMAGES=(
	"01-portrait-2x3   800 1200 #c0504d Alice    Alice portrait"
	"02-landscape-3x2 1200  800 #4f81bd Bob      Bob   landscape"
	"03-square        1000 1000 #9bbb59 Carol    Carol portrait"
	"04-wide-16x9     1280  720 #8064a2 Dave     Dave  street"
	"05-portrait-4x5   800 1000 #f79646 Erin     Erin  portrait"
	"06-panorama-3x1  1500  500 #4bacc6 Frank    Frank landscape"
	"07-tall-1x3       400 1200 #7f7f7f Grace    Grace architecture"
	# No metadata, and smaller than the slideshow: it must not be stretched.
	"08-small          200  150 #2c4d75 -        -     -"
)

xmp() { # title person genre width height
	cat <<XMP
<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/">
 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
  <rdf:Description rdf:about=""
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"
    xmlns:tiff="http://ns.adobe.com/tiff/1.0/"
   photoshop:Headline="$1 at the test site"
   photoshop:City="Testville"
   photoshop:State="Exampleshire"
   photoshop:Country="Nowhere"
   tiff:Make="TestCam"
   tiff:Model="Fixture $4x$5">
   <dc:title><rdf:Alt><rdf:li xml:lang="x-default">$1</rdf:li></rdf:Alt></dc:title>
   <dc:description><rdf:Alt><rdf:li xml:lang="x-default">$1, a $4×$5 test image.</rdf:li></rdf:Alt></dc:description>
   <dc:creator><rdf:Seq><rdf:li>PhotoPress Tests</rdf:li></rdf:Seq></dc:creator>
   <dc:subject><rdf:Bag><rdf:li>genre:$3</rdf:li><rdf:li>person:$2</rdf:li><rdf:li>fixture</rdf:li></rdf:Bag></dc:subject>
  </rdf:Description>
 </rdf:RDF>
</x:xmpmeta>
<?xpacket end="w"?>
XMP
}

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

for row in "${IMAGES[@]}"; do
	read -r name w h color title person genre <<<"$row"
	point=$(( (w < h ? w : h) / 8 ))
	args=( -size "${w}x${h}" "xc:$color" -fill white -gravity center -pointsize "$point" -annotate 0 "${name%%-*}\n${w}×${h}" -strip -quality 70 )

	if [ "$title" != "-" ]; then
		xmp "$title" "$person" "$genre" "$w" "$h" > "$tmp/$name.xmp"
		args+=( -profile "$tmp/$name.xmp" )
	fi

	convert "${args[@]}" "$name.jpg"
done

# A PNG, for replacing a JPEG with another file type. Not one of the gallery
# images (those are the .jpg files).
convert -size 900x600 xc:'#e46c0a' -fill white -gravity center -pointsize 75 -annotate 0 "png\n900×600" -strip 09-png-3x2.png

ls -l ./*.jpg ./*.png
