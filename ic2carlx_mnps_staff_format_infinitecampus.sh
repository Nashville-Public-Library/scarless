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
# 2020-21 CLOSED SCHOOLS: STAFF MAY LINGER AND SHOULD BE DELETED
	if ($F[6] =~ m/^14165$/) { next; }
	if ($F[6] =~ m/^4C365$/) { next; }
	if ($F[6] =~ m/^4G470$/) { next; }
	if ($F[6] =~ m/^26500$/) { next; }
	if ($F[6] =~ m/^7R589$/) { next; }
# 2020 TORNADO: CHANGE GRA-MAR TO JERE BAXTER
        if ($F[6] =~ m/^4C365$/) { $F[6] = "43120"; }
# LEAD NEELYS BEND: UPDATE BRANCHCODE
	if ($F[6] =~ m/^4R601$/) { $F[6] = "7E601"; }
# CHANGE DATE VALUE FOR EXPIRATION TO 2021-09-01
	$F[7] = "2021-09-01";
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
