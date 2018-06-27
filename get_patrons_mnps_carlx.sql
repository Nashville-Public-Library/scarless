select patron_v.patronid as "Patron ID"						-- 00
  , patron_v.bty as "Borrower type code"					-- 01
  , patron_v.lastname as "Patron last name"					-- 02
  , patron_v.firstname as "Patron first name"					-- 03
  , patron_v.middlename as "Patron middle name"					-- 04
  , patron_v.suffixname as "Patron suffix"					-- 05
  , patron_v.street1 as "Primary Street Address"				-- 06
  , patron_v.city1 as "Primary City"						-- 07
  , patron_v.state1 as "Primary State"						-- 08
  , patron_v.zip1 as "Primary Zip Code"						-- 09
--  , '' as "Secondary Street Address"						-- 10
--  , '' as "Secondary City"							-- 11
--  , '' as "Secondary State"							-- 12
--  , '' as "Secondary Zip Code"						-- 13
  , patron_v.ph2 as "Primary Phone Number"					-- 14 -- CONFUSING. MNPS SUPPLIES PRIMARY HOME PHONE. NPL LOADS INTO SECONDARY PHONE BECAUSE STUDENTS SHOULD NOT RECEIVE ITIVA AUTOMATED CALLS
--  , '' as "Secondary Phone Number"						-- 15
--  , '' as "Alternate ID"							-- 16
--  , '' as "Non-validated Stats"						-- 17
  , patronbranch.branchcode as "Default Branch"					-- 18
--  , '' as "Validated Stat Codes"						-- 19
-- TO DO: establish logic for patron status
-- , patron_v.status as "Status Code"
--  , '' as "Status Code"							-- 20
--  , '' as "Registration Date"							-- 21
--  , '' as "Last Action Date"							-- 22
  , to_char(jts.todate(patron_v.expdate),'YYYY-MM-DD') as "Expiration Date"	-- 23
  , patron_v.email as "Email Address"						-- 24
--  , '' as "Notes"								-- 25
  , to_char(jts.todate(patron_v.birthdate),'YYYY-MM-DD') as "Birth Date"	-- 26
  , guarantor.guarantor as "Guarantor"						-- 27
--  , udf2.valuename as "Racial or Ethnic Category"				-- 28
  , udf3.valuename as "Lap Top Check Out"					-- 29
  , udf4.valuename as "Limitless Library Use"					-- 30
  , udf1.valuename as "Tech Opt Out"						-- 31
  , patron_v.street2 as "Teacher ID"						-- 32
  , patron_v.sponsor as "Teacher Name"						-- 33

from patron_v
join branch_v patronbranch on patron_v.defaultbranch = patronbranch.branchnumber
left outer join (
  select distinct
    refid
    , upper(trim(regexp_substr(first_value(text) over (partition by refid order by timestamp desc),'[-/0-9]+:.+$'))) as guarantor
  from patronnotetext_v
  where regexp_like(patronnotetext_v.text, 'NPL: MNPS Guarantor effective')
) guarantor on patron_v.patronid = guarantor.refid
left outer join (
  select distinct
    patronid
    , fieldid
    , valuename
  from udfpatron_v
  where udfpatron_v.fieldid = 1
) udf1 on patron_v.patronid = udf1.patronid
left outer join (
  select distinct
    patronid
    , fieldid
    , valuename
  from udfpatron_v
  where udfpatron_v.fieldid = 2
) udf2 on patron_v.patronid = udf2.patronid
left outer join (
  select distinct
    patronid
    , fieldid
    , valuename
  from udfpatron_v
  where udfpatron_v.fieldid = 3
) udf3 on patron_v.patronid = udf3.patronid
left outer join (
  select distinct
    patronid
    , fieldid
    , valuename
  from udfpatron_v
  where udfpatron_v.fieldid = 4
) udf4 on patron_v.patronid = udf4.patronid
where
   patronbranch.branchgroup = '2'
--   or patron_v.bty = 13 or (patron_v.bty >= 21 and patron_v.bty <= 42)
--   or regexp_like(patron_v.patronid,'^190[0-9]{6}$')
  and regexp_like(patron_v.patronid,'^190999[0-9]{3}$') -- TEST STUDENT PATRONS
order by patron_v.patronid
