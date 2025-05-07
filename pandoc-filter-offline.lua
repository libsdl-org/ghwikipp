local base_url = os.getenv("GHWIKIPP_BASE_URL")
local input_path = os.getenv("GHWIKIPP_INPUT_PATH")
local rawdir = os.getenv("GHWIKIPP_RAW_DIR")

-- !!! FIXME: this is super-gross and unix-specific.
function is_dir(path)
    local p = io.popen("[ -d '" .. path .. "' ] && echo 'yes' || echo 'no'")
    local result = p:read('*l')
    p:close()
    --print("is_dir('" .. path .. "') => " .. result);
    return result == 'yes'
end

local function split_path(str, sep)
    local result = {}
    if str ~= nil then
        for part in string.gmatch(str, '([^' .. sep .. ']+)') do
          table.insert(result, part)
        end
    end
    local fname = result[#result]
    if fname == nil then
        fname = ''
    end
    table.remove(result)  -- drop the filename from the array
    local nofname = ''
    local sep = ''
    for i, v in ipairs(result) do
        nofname = nofname .. sep .. v
        sep = '/'
    end
    return result, fname, nofname
end

local input_path_array, input_path_filename, input_path_nofilename = split_path(input_path, '/')

function Link (link)
    local url = link.target
    local pos, endpos

    -- if there's an anchor (the '#blahblahblah' at the end of a URL),
    -- split it out to a separate var and remove it from the url.
    local anchor = ''
    pos, endpos = string.find(url, '#.*$')
    if pos ~= nil then
        anchor = string.sub(url, pos)
        url = string.sub(url, 1, pos - 1)
    end

    -- http into https on base urls.
    local https_url = string.gsub(url, '^http:', 'https:', 1);

    -- if this a full URL to somewhere else in the wiki, chop off the base so we can use it as a path on the filesystem.
    if https_url == base_url then
        url = '/FrontPage'
    else
        pos, endpos = string.find(https_url, base_url, 1, false)
        if pos == 1 then
            url = string.sub(https_url, endpos + 1)
        end
    end

    if url == '/' then
        url = '/FrontPage'
    end

    -- Most links are relative in the wiki, but occasionally we'll do "/TopLevelDirectory/Whatever" when we have to
    --  access a parent directory, because we can't do ".." paths on the wiki, but these don't fly
    --  when looking at static HTML files on the user's local disk.
    -- If this is an absolute path into the wiki instead of relative, convert it to a relative page.
    -- Note that full URLs (https://wiki.example.com/) will land in here, too, as we intentionally chop off the base_url to leave the initial '/'.
    if string.sub(url, 1, 1) == '/' then  -- if first char is '/', it's an absolute path.
        local split_path_array, split_path_filename = split_path(url, '/')
        local dotdot = false
        local sep = ''
        local final = ''
        for i, v in ipairs(input_path_array) do
            if not dotdot then
                if split_path_array[1] == v then
                    table.remove(split_path_array, 1)
                else
                    dotdot = true
                end
            end
            if dotdot then
                final = final .. sep .. '..'
                sep = '/'
            end
        end

        for i, v in ipairs(split_path_array) do
            final = final .. sep .. v
            sep = '/'
        end

        url = final .. sep .. split_path_filename
    end

    if string.find(url, '^.*://') ~= 1 then  -- if this is an external URL, don't mangle it.
        -- Is this a link to a directory or a file?
        local rawpath = rawdir .. '/' .. input_path_nofilename .. '/' .. url
        if (url ~= '') and is_dir(rawpath) then
            --print("rawpath: '" .. rawpath .. "'")
            -- it's a directory! Either link directly to a FrontPage.html, if one will exist, or just dump the user in the directory itself if not.
            local f = io.open(rawpath .. '/FrontPage.md', 'r')
            if not f then f = io.open(rawpath .. '/FrontPage.mediawiki', 'r') end
            if f then
                f:close()
                url = url .. '/FrontPage.html';
            end
        else
            -- drop any Markdown or MediaWiki file extensions that might have snuck in.
            url = string.gsub(url, '%.md$', '')
            url = string.gsub(url, '%.mediawiki$', '')

            -- Add HTML file extension.
            if url ~= '' then  -- could be '' if there's nothing but an anchor on the current page.
                url = url .. '.html'
            end
        end
    end

    -- Add anchor back in
    url = url .. anchor

    --print(link.target .. ' -> ' .. url)

    link.target = url
    return link
end

