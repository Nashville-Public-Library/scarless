# ic2carlx_mnps_staff_format_infinitecampus.sh
# James Staub
# Nashville Public Library
# Preprocess Infinite Campus Extract before delivery to Carl.X

# STAFF

# SORT REVERSE BY ID+...+SCHOOL CODE TO GET BRANSFORD ON TOP
sort -t'|' -r -k1,7 ../data/CARLX_INFINITECAMPUS_STAFF.txt > ../data/CARLX_INFINITECAMPUS_STAFF.txt.sorted
# SORT UNIQ BY ID
sort -t'|' -k1,1 -u ../data/CARLX_INFINITECAMPUS_STAFF.txt.sorted > ../data/CARLX_INFINITECAMPUS_STAFF.txt.unique

# APPEND TEST PATRONS
cat ../data/ic2carlx_mnps_staff_test.txt ../data/CARLX_INFINITECAMPUS_STAFF.txt.unique > ../data/ic2carlx_mnps_staff_infinitecampus.txt

perl -MLingua::EN::NameCase -F'\|' -lane '
# SCRUB NON-ASCII CHARACTERS
	@F = map { s/[^\012\015\040-\176]//g; $_ } @F;
# ADD EMPTY VALUE[S] TO MATCH PATRON LOADER FORMAT
	@filler = ("");
	splice @F, 7, 0, @filler;
# REMOVE ENTRIES WITHOUT PATRON ID
	if ($F[0] == "") { next; }
# CHANGE CASE TO Title Case IF LAST NAME IS ALL CAPS
	if ($F[2] =~ m/^[- .A-Z\x27]+$/) {
		$F[2] = nc($F[3]);
		$F[3] = nc($F[4]);
		$F[4] = nc($F[5]);
		$F[5] = nc($F[6]);
	}
# REMOVE WHITESPACE FROM SCHOOL CODE
	$F[6] =~ s/\s//g;
# ASD Schools should be BTY out of county educator
	if ($F[6] =~ m/^79118$/) { $F[1] = "12"; }
	if ($F[6] =~ m/^7E601$/) { $F[1] = "12"; }
# CHANGE DATE VALUE FOR EXPIRATION TO 2024-09-01
	$F[7] = "2024-09-01";
# ADD EMAIL NOTICES VALUE 1 = SEND EMAIL NOTICES
	$F[9] = "1";
# ADD EMPTY FOR EXPIRED MNPS NOTE IDS
	$F[10] = "";
# MNPS SCHOOL STAFF COLLECTION STATUS = 78 (do not send)
	if ($F[1] == 13 || $F[1] == 40) { $F[11] = "78"; } else { $F[11] = ""; }
# ADD EMPTY FOR EDIT BRANCH
	$F[12] = "";
# FORMAT AS CSV
	foreach (@F) {
		$_ =~ s/[\n\r]+//g;
		$_ =~ s/^\s+//;
		$_ =~ s/\s+$//;
		if ($_ =~ /,/) {$_ = q/"/ . $_ . q/"/;}
	}
# REPLACE PIPE DELIMITERS WITH COMMAS
	print join q/,/, @F' ../data/ic2carlx_mnps_staff_infinitecampus.txt > ../data/ic2carlx_mnps_staff_infinitecampus.csv;
# REMOVE HEADERS
#perl -pi -e '$_ = "" if ( $. == 1 && $_ =~ /^patronid/i)' ../data/ic2carlx_mnps_staff_infinitecampus.csv
# SORT UNIQ BY ID
sort -t',' -k1,1 -u -o ../data/ic2carlx_mnps_staff_infinitecampus.csv ../data/ic2carlx_mnps_staff_infinitecampus.csv
