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

perl -MLingua::EN::NameCase -MDateTime -MDateTime::Duration -MDateTime::Format::ISO8601 -F'\|' -lane '
# SCRUB HEADERS AND RECORDS WITH WEIRD STUDENT IDS
	if ($F[0] !~ m/^190\d{6}$/) { next; }
# SCRUB NON-ASCII CHARACTERS
	@F = map { s/[^\012\015\040-\176]//g; $_ } @F;
# GRADUATING SENIORS SHOULD BE XMNPS NEAR THE LAST DAY OF SCHOOL - EXCEPT AT HARRIS HILLMAN 65397 AND CORA HOWE 68448
# COMMENT OUT THIS LINE EXCEPT AT THE END OF THE SCHOOL YEAR
#	if ($F[1] == 34 && $F[18] != "65397" && $F[18] != "68448") { next; }
# CHANGE CASE TO Title Case IF LAST NAME IS ALL CAPS
	if ($F[2] =~ m/^(?!TEST)[- .A-Z\x27]+$/) {
		$F[2] = nc($F[2]);
		$F[3] = nc($F[3]);
		$F[4] = nc($F[4]);
		$F[5] = nc($F[5]);
	}
# REMOVE WHITESPACE FROM SCHOOL CODE
	$F[18] =~ s/\s//g;
# SET STUDENTS AT NON-ELIGIBLE SCHOOLS TO THE NO-DELIVERY "SCHOOL" 7Z999
	# MNPS VIRTUAL SCHOOL
	if ($F[18] =~ m/^7F748$/) { $F[18] = "7Z999"; }
	# Bass Alternative Learning Center
	elsif ($F[18] =~ m/^83116/) { $F[18] = "7Z999"; }
	# Transitions at Bass
	elsif ($F[18] =~ m/^84117/) { $F[18] = "7Z999"; }
	# Johnson Alternative Learning Center
	elsif ($F[18] =~ m/^85480/) { $F[18] = "7Z999"; }
	# Robertson Academy Gifted School
	elsif ($F[18] =~ m/^86665/) { $F[18] = "7Z999"; }
# SET BORROWER TYPE FOR LIMITLESS LIBRARIES OPT-OUT STUDENTS
	if ($F[30] =~ m/^N/) {
		if ($F[1] =~ m/^(21|22|23|24|25|26)$/) { $F[1] = 35; }
		elsif ($F[1] =~ m/^(27|28|29|30)$/) { $F[1] = 36; }
		elsif ($F[1] =~ m/^(31|32|33|34)$/) { $F[1] = 37; }
	} 
# IF STUDENT IS 18 YEARS OLD AND BTY IS 31-34, THEN BTY SHOULD BE 46
        if ($F[1] =~ m/^(31|32|33|34)$/ && $F[26] =~ m/^\d{4}-\d{2}-\d{2}$/) {
                $birdate        = $F[26];
                $birdt          = DateTime::Format::ISO8601->parse_datetime($birdate);
                $tnratedrdt     = $birdt + DateTime::Duration->new( years => 18, days => -1 );
                if (DateTime->compare($tnratedrdt,$todaydt) == -1) { $F[1] = 46; }
        }
# IF STUDENT IS 18 YEARS OLD AND BTY IS 37, THEN BTY SHOULD BE 47
        if ($F[1] =~ m/^37$/ && $F[26] =~ m/^\d{4}-\d{2}-\d{2}$/) {
                $birdate        = $F[26];
                $birdt          = DateTime::Format::ISO8601->parse_datetime($birdate);
                $tnratedrdt     = $birdt + DateTime::Duration->new( years => 18, days => -1 );
                if (DateTime->compare($tnratedrdt,$todaydt) == -1) { $F[1] = 47; }
        }
# ELIMINATE EXTRA SPACES FROM ADDRESS FIELD
  $F[6] =~s/  +/ /g;
# ELIMINATE NON-NUMERIC CHARACTERS FROM PHONE NUMBERS LONGER THAN 14 CHARACTERS
	if (length($F[14]) > 14) { $F[14] =~s/\D//g; }
# SET LIMITLESS PERMISSION TO YES IF BLANK
	elsif ($F[30] =~ m/^$/) { $F[30] = "Yes"; }
# CHANGE USER DEFINED FIELDS laptopCheckout limitlessLibrariesuse techOptout from N to No and Y to Yes
	if ($F[29] eq "N") { $F[29] = "No"; }
	if ($F[29] eq "Y") { $F[29] = "Yes"; }
	if ($F[30] eq "N") { $F[30] = "No"; }
	if ($F[30] eq "Y") { $F[30] = "Yes"; }
	if ($F[31] eq "N") { $F[31] = "No"; }
	if ($F[31] eq "Y") { $F[31] = "Yes"; }
# STATUS EMPTY; SHOULD NOT OVERWRITE CARL.X STATUS
	$F[20] = "";
# CHANGE DATE VALUE FOR EXPIRATION TO 2024-10-01
	$F[23] = "2024-10-01";
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
# ADD EMPTY FOR PRIMARY PHONE NUMBER
	$F[39] = "";
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
	print join q/,/, @F[0..9,14,18,23,24,26,27,29..39]' ../data/ic2carlx_mnps_students_infinitecampus.txt > ../data/ic2carlx_mnps_students_infinitecampus.csv;
# REMOVE HEADERS
#perl -pi -e '$_ = "" if ( $. == 1 && $_ =~ /^patronid/i)' ../data/ic2carlx_mnps_students_infinitecampus.csv
