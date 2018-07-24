This is a patron loader built with [TLC](https://tlcdelivers.com/)'s [CarlX API](https://tlcdelivers.com/2016/06/21/carlx-api-version-1-7-8-released/). It converts [Infinite Campus](https://www.infinitecampus.com/) student data fields to [CarlX](https://tlcdelivers.com/carlsystem/) patron fields with help from additional scripts in this repository. 

Students
=========

|Infinite Campus EXTRACT FIELD   |If CarlX value is NULL, CarlX value should be |If CarlX value not NULL, CX Value should be |  
|-----|-----|-----|
|patronId|Infinite Campus value |N/A; this is MATCHPOINT|
|borrowerTypecode|Infinite Campus value |Infinite Campus value|
|patronLastname|Infinite Campus value |Infinite Campus value|
|patronFirstname|Infinite Campus value |Infinite Campus value|
|patronMiddlename|Infinite Campus value |Infinite Campus value |
|Patronsuffix|Infinite Campus value |Infinite Campus value |
|primaryStreetaddress|Infinite Campus value |Infinite Campus value |
|primaryCity|Infinite Campus value |Infinite Campus value |
|primaryState|Infinite Campus value |Infinite Campus value |
|primaryZipcode|Infinite Campus value |Infinite Campus value |
|secondaryStreetaddress|NULL |CarlX value |		
|secondaryCity|NULL |	CarlX	value |
|secondaryState|NULL |CarlX value |		
|secondaryZipcode|NULL |CarlX value |		
|primaryPhonenumber|NULL |CarlX value |		
|secondaryPhonenumber|Infinite Campus value |Infinite Campus value |		
|alternateId|NULL |CarlX value |		
|nonvalidatedStats|NULL |CarlX value |		
|defaultBranch|Infinite Campus value |Infinite Campus value |		
|validatedStatcodes|NULL |CarlX value |		
|statusCode|"G" |CarlX value |		
|registrationDate|TODAY |CarlX value |		
|lastActiondate|TODAY |CarlX value |		
|expirationDate|Infinite Campus value |Infinite Campus value |		
|emailAddress|Infinite Campus value |* |		
|notes|NULL |CarlX value |		
|birthDate|Infinite Campus value |Infinite Campus value |		
|guardian|Infinite Campus value |** |		
|racialOrethniccategory|NULL |CarlX value |		
|laptopCheckout|Infinite Campus value |Infinite Campus value |		
|limitlessLibrariesuse|Infinite Campus value |Infinite Campus value |		
|techOptout|Infinite Campus value |Infinite Campus value |		
|teacherId|Infinite Campus value |Infinite Campus value |		
|teacherName|Infinite Campus value |Infinite Campus value |		
|EmailNotices|Infinite Campus value |Always set to "Yes"* |		
|ExpiredNoteIDs|NULL |CarlX value |		
|DeleteGuarantorNoteIDs|NULL |CarlX value |		
|CollectionStatus|do not send |if CX=="sent", then "sent"; else "do not send" |				

\* If email domain is "mnpsk12.org," then CX Email Notice status set to "Yes" even if CX Email Status is "Bounced." CX Email Notice value of "Yes" is kept. CX Email Notice value of "No - Do not send" or "No - Opted out," then CX Email Notice status set to "Yes."

\*\* Guarantor status will appear as CX Note. In addition to new CX Note, previous Guarantor CX Note will kept if outstanding checkouts or fees within previous Guarantor effective dates. Start value will be first day of school, or date of Guarantor's appearance in IC extract. Stop date will be day before student's thirteenth birthday, the date the Guarantor stops appearing, or the presumed last day of school.  

Staff
=====

|Infinite Campus EXTRACT FIELD   |If CarlX value is NULL, CarlX value should be |If CarlX value not NULL, CX Value should be |  
|-----|-----|-----|
|patronId|Infinite Campus value |N/A; this is MATCHPOINT|
|borrowerTypecode|Infinite Campus value |Infinite Campus value|
|patronLastname|Infinite Campus value |Infinite Campus value|
|patronFirstname|Infinite Campus value |Infinite Campus value|
|patronMiddlename|Infinite Campus value |Infinite Campus value |
|Patronsuffix|Infinite Campus value |Infinite Campus value |
|defaultBranch|Infinite Campus value |Infinite Campus value |		
|statusCode|"G" |CarlX value |		
|registrationDate|TODAY |CarlX value |		
|lastActiondate|TODAY |CarlX value |		
|expirationDate|Infinite Campus value |Infinite Campus value |		
|emailAddress|Infinite Campus value |Infinite Campus value |		
|EmailNotices|"Yes" |"Yes" |
|ExpiredNoteIDs|NULL |CarlX value |		
|CollectionStatus|"do not send" |if CX=="sent", then "sent"; else "do not send" |		
