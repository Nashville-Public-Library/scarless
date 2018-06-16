# format_patrons_mnps_infinitecampus.sh
# James Staub
# Nashville Public Library
# Preprocess Infinite Campus patron data extract

# STUDENTS

perl -F'\|' -lane '
# SCRUB NON-ASCII CHARACTERS
	@F = map { s/[^\012\015\040-\176]//g; $_ } @F;
# 20180501 ELIMINATE 2017-2018 12TH GRADERS FROM CARL PATRON LOAD
	if ($F[1] == 34) { next; }
# MCMURRAY 5-6TH GRADE REASSIGN TO MCMURRAY ANNEX @ TUSCULUM
	if ($F[18] =~ m/^4K540$/ && ($F[1] == 27 || $F[1] == 28)) { $F[18] = "4Y541"; }
# SKIP STUDENTS AT NON-ELIGIBLE SCHOOLS
	# Academy at Old Cockrill
	if ($F[18] =~ m/^72211$/) { next; }
	# Academy at Hickory Hollow
	elsif ($F[18] =~ m/^73422$/) { next; }
	# Middle College High
	elsif ($F[18] =~ m/^74562$/) { next; }
	# Murrell School
	elsif ($F[18] =~ m/^75585$/) { next; }
	# Academy at Opry Mills
	elsif ($F[18] =~ m/^76613$/) { next; }
# ASSIGN NON-DELIVERY BORROWER TYPE TO ONLINE-ONLY STUDENT PATRONS
	# NASHVILLE BIG PICTURE
	elsif ($F[18] =~ m/^(70142)$/) { $F[1] = 37; }
# THE FOLLOWING LOCATIONS ARE NOW SET IN PIKA AS NOT VALID HOLD PICKUP BRANCHES
# TO FACILITATE THESE STUDENTS PLACING HODS FOR PICKUP AT AN NPL BRANCH
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
	elsif ($F[30] =~ m/^N/) {
		if ($F[1] =~ m/^(25|26)$/) { $F[1] = 35; }
		elsif ($F[1] =~ m/^(27|28|29|30)$/) { $F[1] = 36; }
		elsif ($F[1] =~ m/^(31|32|33|34)$/) { $F[1] = 37; }
	} 
# SET LIMITLESS PERMISSION TO YES IF BLANK
	elsif ($F[30] =~ m/^$/) { $F[30] = "Yes"; }
# CHANGE USER DEFINED FIELDS laptopCheckout limitlessLibrariesuse techOptout from N to No and Y to Yes
	if ($F[29] eq "N") { $F[29] = "No"; }
	if ($F[29] eq "Y") { $F[29] = "Yes"; }
	if ($F[30] eq "N") { $F[30] = "No"; }
	if ($F[30] eq "Y") { $F[30] = "Yes"; }
	if ($F[31] eq "N") { $F[31] = "No"; }
	if ($F[31] eq "Y") { $F[31] = "Yes"; }
# SET STATUS = GOOD; SHOULD NOT OVERWRITE CARL.X STATUS
	$F[20] = "G";
# NORMALIZE DATE VALUE FOR EXPIRATION
#	if ($F[23] =~ /(\d{2})\/(\d{2})\/(\d{4})/) { $F[23] =~ s/(\d{2})\/(\d{2})\/(\d{4})/$3-$1-$2/; }
# CHANGE DATE VALUE FOR EXPIRATION TO 2018-08-04
	$F[23] = "2018-08-04";
# FORMAT AS CSV
	foreach (@F) {
		# CHANGE QUOTATION MARK IN ALL FIELDS TO AN APOSTROPHE
		$_ =~ s/"/\047/g;
		$_ =~ s/[\n\r]+//g;
		$_ =~ s/^\s+//;
		$_ =~ s/\s+$//;
		if ($_ =~ /[, ]/) {$_ = q/"/ . $_ . q/"/;}
	}
# REPLACE PIPE DELIMITERS WITH COMMAS
	print join q/,/, @F' ../data/CARLX_INFINITECAMPUS_STUDENT.txt > ../data/INFINITECAMPUS_STUDENT.txt;
# REMOVE MILLENNIUM HEADERS
perl -pi -e '$_ = "" if ( $. == 1 )' ../data/INFINITECAMPUS_STUDENT.txt
# SORT BY ID
sort -o ../data/INFINITECAMPUS_STUDENT.txt ../data/INFINITECAMPUS_STUDENT.txt
# REPLACE HEADERS
perl -pi -e '$_ = qq/"Patron ID","Borrower type code","Patron last name","Patron first name","Patron middle name","Patron suffix","Primary Street Address","Primary City","Primary State","Primary Zip Code","Secondary Street Address","Secondary City","Secondary State","Secondary Zip Code","Primary Phone Number","Secondary Phone Number","Alternate ID","Non-validated Stats","Default Branch","Validated Stat Codes","Status Code","Registration Date","Last Action Date","Expiration Date","Email Address","Notes","Birth Date","Guardian","Racial or Ethnic Category","Lap Top Check Out","Limitless Library Use","Tech Opt Out","Teacher ID","Teacher Name"\n/ if ( $. == 1 )' ../data/INFINITECAMPUS_STUDENT.txt

