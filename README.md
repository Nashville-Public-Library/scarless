This a patron loader built with [TLC](https://tlcdelivers.com/)'s [CarlX API](https://tlcdelivers.com/2016/06/21/carlx-api-version-1-7-8-released/). It converts fields from [Infinite Campus](https://www.infinitecampus.com/) to [CarlX](https://tlcdelivers.com/carlsystem/) patron fields. 

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
|emailAddress|Infinite Campus value |IT'S COMPLICATED |		
|notes|NULL |CarlX value |		
|birthDate|Infinite Campus value |Infinite Campus value |		
|guardian|Infinite Campus value |	IT'S COMPLICATED |		
|racialOrethniccategory|NULL |CarlX value |		
|laptopCheckout|Infinite Campus value |Infinite Campus value |		
|limitlessLibrariesuse|Infinite Campus value |Infinite Campus value |		
|techOptout|Infinite Campus value |Infinite Campus value |		
|teacherId|Infinite Campus value |Infinite Campus value |		
|teacherName|Infinite Campus value |Infinite Campus value |		
|EmailNotices|Infinite Campus value |IT'S COMPLICATED |		
|ExpiredNoteIDs|NULL |CarlX value |		
|DeleteGuarantorNoteIDs|NULL |CarlX value |		
|CollectionStatus|do not send |if CX=="sent", then "sent"; else "do not send" |				
