select patron_v.patronid as "Patron ID"
  , patron_v.bty as "Borrower type code"
  , patron_v.lastname as "Patron last name"
  , patron_v.firstname as "Patron first name"
  , patron_v.middlename as "Patron middle name"
  , patron_v.suffixname as "Patron suffix"
  , patron_v.street1 as "Primary Street Address"
  , patron_v.city1 as "Primary City"
  , patron_v.state1 as "Primary State"
  , patron_v.zip1 as "Primary Zip Code"
  , '' as "Secondary Street Address"
  , '' as "Secondary City"
  , '' as "Secondary State"
  , '' as "Secondary Zip Code"
  , patron_v.ph2 as "Primary Phone Number" -- CONFUSING. MNPS SUPPLIES PRIMARY HOME PHONE. NPL LOADS INTO SECONDARY PHONE BECAUSE STUDENTS SHOULD NOT RECEIVE ITIVA AUTOMATED CALLS
  , '' as "Secondary Phone Number"
  , '' as "Alternate ID"
  , '' as "Non-validated Stats"
  , patronbranch.branchcode as "Default Branch"
  , '' as "Validated Stat Codes"
-- TO DO: establish logic for patron status
-- , patron_v.status as "Status Code"
  , '' as "Status Code"
  , '' as "Registration Date"
  , '' as "Last Action Date"
  , to_char(jts.todate(patron_v.expdate),'YYYY-MM-DD') as "Expiration Date"
  , patron_v.email as "Email Address"
  , '' as "Notes"
  , to_char(jts.todate(patron_v.birthdate),'YYYY-MM-DD') as "Birth Date"
  , guarantor.guarantor as "Guardian" -- FIXED!
-- TO DO: endure udf values from carlx match those from infinitecampus (i.e., "Yes" and "No", not "Y" and "N")
  , udf2.valuename as "Racial or Ethnic Category" -- FIX THIS
  , udf3.valuename as "Lap Top Check Out" -- FIX THIS
  , udf4.valuename as "Limitless Library Use" -- FIX THIS
  , udf1.valuename as "Tech Opt Out" -- FIXED?
  , patron_v.street2 as "Teacher ID"
  , patron_v.sponsor as "Teacher Name"

from patron_v
join branch_v patronbranch on patron_v.defaultbranch = patronbranch.branchnumber
left outer join (
  select distinct
    refid
    , first_value(text) over (partition by refid order by timestamp desc) as guarantor
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

