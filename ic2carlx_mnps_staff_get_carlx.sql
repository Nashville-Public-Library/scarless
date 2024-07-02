select patron_v2.patronid as "Patron ID"						-- 00
  , patron_v2.bty as "Borrower type code"					-- 01
  , patron_v2.lastname as "Patron last name"					-- 02
  , patron_v2.firstname as "Patron first name"					-- 03
  , patron_v2.middlename as "Patron middle name"					-- 04
  , patron_v2.suffixname as "Patron suffix"					-- 05
--  , patron_v2.street1 as "Primary Street Address"				-- 06
--  , patron_v2.city1 as "Primary City"						-- 07
--  , patron_v2.state1 as "Primary State"					-- 08
--  , patron_v2.zip1 as "Primary Zip Code"					-- 09
--  , '' as "Secondary Street Address"						-- 10
--  , '' as "Secondary City"							-- 11
--  , '' as "Secondary State"							-- 12
--  , '' as "Secondary Zip Code"						-- 13
--  , patron_v2.ph2 as "Primary Phone Number"					-- 14
--  , '' as "Secondary Phone Number"						-- 15
--  , '' as "Alternate ID"							-- 16
--  , '' as "Non-validated Stats"						-- 17
  , patronbranch.branchcode as "Default Branch"					-- 18 -- 06
--  , '' as "Validated Stat Codes"						-- 19
-- TO DO: establish logic for patron status
-- , patron_v2.status as "Status Code"
--  , '' as "Status Code"							-- 20
--  , '' as "Registration Date"							-- 21
--  , '' as "Last Action Date"							-- 22
  , to_char(jts.todate(patron_v2.expdate),'YYYY-MM-DD') as "Expiration Date"	-- 23 -- 07
  , patron_v2.email as "Email Address"						-- 24 -- 08
--  , '' as "Notes"								-- 25
--  , to_char(jts.todate(patron_v2.birthdate),'YYYY-MM-DD') as "Birth Date"	-- 26
--  , guarantor.guarantor as "Guarantor"					-- 27
--  , udf2.valuename as "Racial or Ethnic Category"				-- 28
--  , udf3.valuename as "Lap Top Check Out"					-- 29
--  , udf4.valuename as "Limitless Library Use"					-- 30
--  , udf1.valuename as "Tech Opt Out"						-- 31
--  , patron_v2.street2 as "Teacher ID"						-- 32
--  , patron_v2.sponsor as "Teacher Name"					-- 33
  , patron_v2.emailnotices as "Email Notices"					-- 34 -- 09
  , expired.noteids as "Expired MNPS Note IDs"					-- 35 -- 10
--  , gDeleteNotes.deleteGuarantorNotes as "Delete Guarantor Note IDs"		-- 36
  , patron_v2.collectionstatus as "Collection Status"				-- 37 -- 11
  , editbranch.branchcode as "Edit Branch"					-- 38 -- 12

from patron_v2
left outer join branch_v2 patronbranch on patron_v2.defaultbranch = patronbranch.branchnumber
left outer join (
  select refid
    , listagg(noteid,',') within group (order by timestamp desc) as noteids
  from patronnotetext_v2
  where regexp_like(patronnotetext_v2.text, '(MNPS patron expired|Former MNPS staffer, patron expired)')
  group by refid
) expired on patron_v2.patronid = expired.refid
left outer join branch_v2 editbranch on patron_v2.editbranch = editbranch.branchnumber
where
  patron_v2.bty in (13,40,51)
  or regexp_like(patron_v2.patronid,'^[0-9]{6}$')
  or (regexp_like(patron_v2.patronid,'^[46][0-9]{6}$') and patron_v2.patronid != '6150737') -- excluded patronid is NPL staff shared id for discovery layer lists, etc.
  or patronid = '9999681' -- ROSS EARLY LEARNING CENTER librarian card to accommodate shared staffing at another site
  or regexp_like(patron_v2.patronid,'^999[0-9]{3}$') -- TEST MNPS STAFF PATRONS
order by patron_v2.patronid
