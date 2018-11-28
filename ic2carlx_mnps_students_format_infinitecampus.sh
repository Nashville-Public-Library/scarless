# ic2carlx_mnps_students_format_infinitecampus.sh
# James Staub
# Nashville Public Library
# Preprocess Infinite Campus patron data extract

# STUDENTS

# APPEND TEST PATRONS
cat ../data/ic2carlx_mnps_students_test.txt ../data/CARLX_INFINITECAMPUS_STUDENT.txt > ../data/ic2carlx_mnps_students_infinitecampus.txt
# USE ONLY TEST PATRONS
#cat ../data/ic2carlx_mnps_students_test.txt > ../data/ic2carlx_mnps_students_infinitecampus.txt

# SORT AND UNIQ PATRONS ATTEMPTING TO GET THE MOST COMPLETE RECORD 
# BY SORTING ID ASC, ADDRESS DESC TO PUSH BLANK ADDRESSES TO THE BOTTOM
sort -t\| -k1,1 -k7,7r -o ../data/ic2carlx_mnps_students_infinitecampus.txt ../data/ic2carlx_mnps_students_infinitecampus.txt
sort -t\| -k1,1 -u -o ../data/ic2carlx_mnps_students_infinitecampus.txt ../data/ic2carlx_mnps_students_infinitecampus.txt

perl -MDateTime -MDateTime::Duration -MDateTime::Format::ISO8601 -F'\|' -lane '
# SCRUB HEADERS AND RECORDS WITH WEIRD STUDENT IDS
	if ($F[0] !~ m/^190\d{6}$/) { next; }
# SCRUB NON-ASCII CHARACTERS
	@F = map { s/[^\012\015\040-\176]//g; $_ } @F;
# LEFT PAD WITH ZEROES EARLY LEARNING CENTERS
	if (length($F[18]) == 3) { $F[18] = "00" . $F[18]; }
	if (length($F[18]) == 4) { $F[18] = "0" . $F[18]; }
# FIX DAVIS ELC DEFAULTBRANCH CODE
	if ($F[18] == "02152") { $F[18] = "00152"; }
# FIX CUMBERLAND ELEMENTARY DEFAULTBRANCH CODE
        if ($F[18] == "1.00E+240") { $F[18] = "1E240"; }
# SET STUDENTS AT NON-ELIGIBLE SCHOOLS TO THE NO-DELIVERY "SCHOOL" 7Z999
	# NASHVILLE BIG PICTURE SCHOOL
	if ($F[18] =~ m/^(70142)$/) { $F[18] = "7Z999"; }
	# Academy at Old Cockrill
	elsif ($F[18] =~ m/^72211$/) { $F[18] = "7Z999"; }
	# Academy at Hickory Hollow
	elsif ($F[18] =~ m/^73422$/) { $F[18] = "7Z999"; }
	# Middle College High
	elsif ($F[18] =~ m/^74562$/) { $F[18] = "7Z999"; }
	# Academy at Opry Mills
	elsif ($F[18] =~ m/^76613$/) { $F[18] = "7Z999"; }
	# MNPS VIRTUAL SCHOOL
	elsif ($F[18] =~ m/^(7F748)$/) { $F[18] = "7Z999"; }
# SKIP STUDENTS AT OPPORTUNITY MIDDLE; KEEP STUDENTS AT SISTER CHARTER INDEPENDENCE ACADEMY
	elsif ($F[18] =~ m/^7G457$/ && $F[1] >= 21 && $F[1] <= 30) { next; }
# THE FOLLOWING LOCATIONS ARE NOW SET IN PIKA AS NOT VALID HOLD PICKUP BRANCHES
# TO FACILITATE THESE STUDENTS PLACING HOLDS FOR PICKUP AT AN NPL BRANCH
	# NEELYS BEND COLLEGE PREP BRANCH CODE FOR STUDENTS FROM 4R601 TO 7E601
	elsif ($F[18] =~ m/^(4R601)$/) { $F[18] = "7E601"; }
#	elsif ($F[18] =~ m/^(4R601)$/) { $F[1] = 36; $F[18] = "7E601"; }
	# BRICK CHURCH COLLEGE PREP
#	elsif ($F[18] =~ m/^(79118)$/) { $F[1] = 36; }
	# KIPP NASHVILLE COLLEGIATE HIGH
#	elsif ($F[18] =~ m/^(7A504)$/) { $F[1] = 37; }
	# LEAD PREP SOUTHEAST
#	elsif ($F[18] =~ m/^(7B507)$/) { $F[1] = 36; }
	# VALOR FLAGSHIP ACADEMY
#	elsif ($F[18] =~ m/^(7C743)$/) { $F[1] = 37; }
	# VALOR VOYAGER ACADEMY
#	elsif ($F[18] =~ m/^(7D744)$/) { $F[1] = 37; }
# SET BORROWER TYPE FOR LIMITLESS LIBRARIES OPT-OUT STUDENTS
#	elsif ($F[30] =~ m/^N/) {
# 2018-2019 SCHOOL YEAR INFINITECAMPUS FLIPPED POLARITY ON THIS PERMISSION
	elsif ($F[30] =~ m/^Y/) {
		if ($F[1] =~ m/^(25|26)$/) { $F[1] = 35; }
		elsif ($F[1] =~ m/^(27|28|29|30)$/) { $F[1] = 36; }
		elsif ($F[1] =~ m/^(31|32|33|34)$/) { $F[1] = 37; }
	} 
# ELIMINATE NON-NUMERIC CHARACTERS FROM PHONE NUMBERS LONGER THAN 14 CHARACTERS
	if (length($F[14]) > 14) { $F[14] =~s/\D//g; }
# SET LIMITLESS PERMISSION TO YES IF BLANK
	elsif ($F[30] =~ m/^$/) { $F[30] = "Yes"; }
# CHANGE USER DEFINED FIELDS laptopCheckout limitlessLibrariesuse techOptout from N to No and Y to Yes
	if ($F[29] eq "N") { $F[29] = "No"; }
	if ($F[29] eq "Y") { $F[29] = "Yes"; }
# 2018-2019 SCHOOL YEAR INFINITECAMPUS FLIPPED POLARITY ON THIS PERMISSION
	if ($F[30] eq "N") { $F[30] = "Yes"; }
	if ($F[30] eq "Y") { $F[30] = "No"; }
	if ($F[31] eq "N") { $F[31] = "No"; }
	if ($F[31] eq "Y") { $F[31] = "Yes"; }
# STATUS EMPTY; SHOULD NOT OVERWRITE CARL.X STATUS
	$F[20] = "";
# CHANGE DATE VALUE FOR EXPIRATION TO 2019-09-01
	$F[23] = "2019-09-01";
# GUARANTOR EFFECTIVE STOP DATE (GESD)
	if ($F[27] ne "" && $F[26] =~ m/^\d{4}-\d{2}-\d{2}$/) {
		$todaydt	= DateTime->today();
		$expdate 	= $F[23];
		$expdt   	= DateTime::Format::ISO8601->parse_datetime($expdate);
		$birdate 	= $F[26];
		$birdt		= DateTime::Format::ISO8601->parse_datetime($birdate);
		$gesdt		= $birdt + DateTime::Duration->new( years => 13, days => -1 );
# GUARANTOR NOTE NOT INCLUDED IF PATRON IS 13+ YEARS OLD
		if (DateTime->compare($gesdt,$todaydt) == -1) {
			$F[27] = "";
# PREPEND GESD TO GUARANTOR FOR COMPARISON AGAINST CARL
		} elsif (DateTime->compare($gesdt,$expdt) == 1) {
			$gesdate = $expdt->date();
			$F[27] = $gesdate . ": " . $F[27];
# PREPEND GESD TO GUARANTOR FOR COMPARISON AGAINST CARL
		} else {
			$gesdate = $gesdt->date();
			$F[27] = $gesdate . ": " . $F[27];
		}
	} elsif ($F[27] ne "") {
# -- IF BIRTHDATE IS EMPTY OR INCORRECT FORMAT, SET GESD TO EXPIRATION DATE
		$gesdate = $expdt->date();;
		$F[27] = $gesdate . ": " . $F[27];
	}
# ADD EMAIL NOTICES VALUE 1 = SEND EMAIL NOTICES
	$F[34] = "1";
# ADD EMPTY FOR EXPIRED MNPS NOTE IDS
	$F[35] = "";
# ADD EMPTY FOR DELETE GUARANTOR NOTE IDS
	$F[36] = "";
# COLLECTION STATUS = 78 (do not send)
	$F[37] = "78";
# ADD EMPTY FOR EDIT BRANCH
	$F[38] = "";
# FORMAT AS CSV
	foreach (@F) {
		# CHANGE QUOTATION MARK IN ALL FIELDS TO AN APOSTROPHE
		$_ =~ s/"/\047/g;
		$_ =~ s/[\n\r]+//g;
		$_ =~ s/^\s+//;
		$_ =~ s/\s+$//;
		if ($_ =~ /[, ]/) {$_ = q/"/ . $_ . q/"/;}
	}
# REPLACE PIPE DELIMITERS WITH COMMAS, ELIMINATE COLUMNS THAT WILL NOT BE COMPARED
	print join q/,/, @F[0..9,14,18,23,24,26,27,29..38]' ../data/ic2carlx_mnps_students_infinitecampus.txt > ../data/ic2carlx_mnps_students_infinitecampus.csv;
# REMOVE HEADERS
#perl -pi -e '$_ = "" if ( $. == 1 && $_ =~ /^patronid/i)' ../data/ic2carlx_mnps_students_infinitecampus.csv
