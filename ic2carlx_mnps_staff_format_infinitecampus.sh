# ic2carlx_mnps_staff_format_infinitecampus.sh
# James Staub
# Nashville Public Library
# Preprocess Infinite Campus Extract before delivery to Carl.X

# STAFF

# APPEND TEST PATRONS
cat ../data/ic2carlx_mnps_staff_test.txt ../data/CARLX_INFINITECAMPUS_STAFF.txt > ../data/ic2carlx_mnps_staff_infinitecampus.txt

perl -F'\|' -lane '
# SCRUB NON-ASCII CHARACTERS
	@F = map { s/[^\012\015\040-\176]//g; $_ } @F;
# ADD EMPTY VALUE[S] TO MATCH PATRON LOADER FORMAT
	@filler = ("");
	splice @F, 7, 0, @filler;
# REMOVE SPECTRUM EMPLOYEES - I.E., REMOVE ALL 7-DIGIT EMPLOYEE IDS STARTING 658
	if ($F[0] =~ m/^658\d{4}$/) { next; }
# SCHOOL LIBRARIANS
# Most Librarians/Library Clerks should be set to BTY 40 in the Infinite Campus extract. 
# The list below is for stragglers.
# TO DO : make ad hoc report!
# select patronid from patron_v where bty = 40 and length(patronid) = 6 order by patronid;
	@schoolLibrarians = (183231,
		271653,
		373248,
		406230,
		433782,
		498344,
		647724,
		719546,
		866487);
	if (grep(/^$F[0]$/,@schoolLibrarians)) {$F[1]=40;}
# STAFF HARDCODED STUFF
	# 20180125 259150 Taylor Brophy should be at Eakin ES
	if ($F[0]==259150) {$F[6]="1H280";}
	# 20171024 501277 Ann Martin should be at Bellevue MS
	if ($F[0]==501277) {$F[6]="44130";} 
	# 20180306 505725 Kathleen McGee should be at Norman Binkley ES
	if ($F[0]==505725) {$F[6]="13145";}
	# 20171025 643626 Rachael Black should be at Glenview ES
	if ($F[0]==643626) {$F[6]="1P345";}
# LEFT PAD WITH ZEROES EARLY LEARNING CENTERS
	if (length($F[6]) == 3) { $F[6] = "00" . $F[6]; }
	if (length($F[6]) == 4) { $F[6] = "0" . $F[6]; }
# FIX CUMBERLAND ELEMENTARY DEFAULTBRANCH CODE
	if ($F[6] == "1.00E+240") { $F[6] = "1E240"; }
# FIX DAVIS ELC DEFAULTBRANCH CODE
        if ($F[6] == "02152") { $F[6] = "00152"; }
# CHANGE DATE VALUE FOR EXPIRATION TO 2020-09-01
	$F[7] = "2020-09-01";
# REMOVE STAFF RECORDS ASSOCIATED WITH usd475.org EMAIL
	if ($F[8] =~ m/usd475\.org/) { next; }
# ADD EMAIL NOTICES VALUE 1 = SEND EMAIL NOTICES
	$F[9] = "1";
# ADD EMPTY FOR EXPIRED MNPS NOTE IDS
	$F[10] = "";
# COLLECTION STATUS = 78 (do not send)
	$F[11] = "78";
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
